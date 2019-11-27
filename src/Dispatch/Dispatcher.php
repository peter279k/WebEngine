<?php
namespace Gt\WebEngine\Dispatch;

use Gt\Config\Config;
use Gt\Cookie\CookieHandler;
use Gt\Csrf\HTMLDocumentProtector;
use Gt\Csrf\TokenStore;
use Gt\Database\Database;
use Gt\Http\Header\Headers;
use Gt\Http\ServerInfo;
use Gt\Http\Uri;
use Gt\Input\Input;
use Gt\Session\Session;
use Gt\WebEngine\FileSystem\Assembly;
use Gt\WebEngine\Logic\AbstractLogic;
use Gt\WebEngine\Logic\ApiSetup;
use Gt\WebEngine\Logic\LogicFactory;
use Gt\WebEngine\Logic\LogicPropertyStore;
use Gt\WebEngine\Logic\LogicPropertyStoreReader;
use Gt\WebEngine\Logic\PageSetup;
use Gt\WebEngine\Response\ApiResponse;
use Gt\WebEngine\Response\PageResponse;
use Gt\WebEngine\View\ApiView;
use Gt\WebEngine\View\PageView;
use Gt\WebEngine\View\View;
use Gt\WebEngine\Route\Router;
use Gt\WebEngine\FileSystem\BasenameNotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TypeError;

abstract class Dispatcher implements RequestHandlerInterface {
	/** @var Router */
	protected $router;
	/** @var string */
	protected $appNamespace;
	/** @var TokenStore */
	protected $csrfProtection;
	/** @var bool True if the current execution of `handle` is an error */
	protected $errorHandlingFlag;
	/** @var LogicFactory */
	protected $logicFactory;
	/** @var ?LogicPropertyStore */
	protected $logicPropertyStore;

	public function __construct(Router $router, string $appNamespace) {
		$this->router = $router;
		$this->appNamespace = $appNamespace;
		$this->errorHandlingFlag = false;
		$this->logicFactory = new LogicFactory();
		$this->logicPropertyStore = null;
	}

	public function storeInternalObjects(
		Config $config,
		ServerInfo $serverInfo,
		Input $input,
		CookieHandler $cookie,
		Session $session,
		Database $database,
		Headers $headers
	):void {
		$this->logicFactory->setConfig($config);
		$this->logicFactory->setServerInfo($serverInfo);
		$this->logicFactory->setInput($input);
		$this->logicFactory->setCookieHandler($cookie);
		$this->logicFactory->setSession($session);
		$this->logicFactory->setDatabase($database);
		$this->logicFactory->setHeaders($headers);

	}

	public function setCsrfProtection(TokenStore $csrfProtection):void {
		$this->csrfProtection = $csrfProtection;
	}

	/**
	 * Handle the request and return a response.
	 */
	public function handle(ServerRequestInterface $request):ResponseInterface {
		$uriPath = $request->getUri()->getPath();
		$response = null;

		if($this instanceof PageDispatcher) {
			$response = new PageResponse();
		}
		else {
			$response = new ApiResponse();
		}

		/** @var View|PageView|ApiView|null $view */
		$view = null;
		$templateDirectory = implode(DIRECTORY_SEPARATOR, [
			$this->router->getBaseViewLogicPath(),
			"_component",
		]);

		try {
			$this->router->redirectInvalidPaths($uriPath);
			$viewAssembly = $this->router->getViewAssembly();
			$view = $this->getView(
				$response->getBody(),
				(string)$viewAssembly,
				$templateDirectory,
				$uriPath,
				$request->getHeaderLine("accept")
			);
		}
		catch(BasenameNotFoundException $exception) {
			http_response_code(404);
		}
		finally {
// Set an empty view if we have a 404.
			if(is_null($view)) {
				$view = $this->getView(
					$response->getBody(),
					"",
					$templateDirectory
				);
			}
		}

		$this->logicFactory->setView($view);
		$baseLogicDirectory = $this->router->getBaseViewLogicPath();
		$logicAssembly = $this->router->getLogicAssembly();

// TODO: Opportunity for dependency injection:
		$this->logicPropertyStore = new LogicPropertyStore();

		$logicObjects = $this->createLogicObjects(
			$logicAssembly,
			$baseLogicDirectory,
			$request->getUri(),
			$this->logicPropertyStore
		);

		$this->dispatchLogicObjects(
			$logicObjects,
			$this->logicPropertyStore
		);
		$this->injectCsrf($view);
		if(!$this->errorHandlingFlag
		&& $errorResponse = $this->httpErrorResponse($request)) {
			return $errorResponse;
		}

		if($view instanceof PageView) {
			$view->getViewModel()->removeTemplateAttributes();
		}
		$view->stream();

		$response = $response->withHeader(
			"Content-type",
			$this->router->getContentType()
		);
		return $response;
	}

	/** @throws BasenameNotFoundException */
	protected abstract function getView(
		StreamInterface $outputStream,
		string $body,
		string $templateDirectory,
		string $path = null,
		string $type = null
	):View;

	protected abstract function getBaseLogicDirectory(string $docRoot):string;

	protected function streamResponse(string $viewFile, StreamInterface $body) {
		$bodyContent = file_get_contents($viewFile);
		$body->write($bodyContent);
	}

	/**
	 * @return AbstractLogic[]
	 */
	protected function createLogicObjects(
		Assembly $logicAssembly,
		string $baseLogicDirectory,
		UriInterface $uri,
		LogicPropertyStore $logicPropertyStore
	):array {
		$logicObjects = [];

		foreach($logicAssembly as $logicPath) {
			try {
				$logicObjects []= $this->logicFactory->createLogicObjectFromPath(
					$logicPath,
					$this->appNamespace,
					$baseLogicDirectory,
					$uri,
					$logicPropertyStore
				);
			}
			catch(TypeError $exception) {
				throw new IncorrectLogicObjectType($logicPath);
			}
		}

		return $logicObjects;
	}

	/**
	 * @param AbstractLogic[] $logicObjects
	 */
	protected function dispatchLogicObjects(
		array $logicObjects,
		LogicPropertyStore $logicPropertyStore
	):void {
		foreach($logicObjects as $i => $setupLogic) {
			if($setupLogic instanceof ApiSetup
			|| $setupLogic instanceof PageSetup) {
				$setupLogic->go();
				unset($logicObjects[$i]);
			}
		}

		foreach($logicObjects as $logic) {
			$this->setLogicProperties($logic, $logicPropertyStore);
			$logic->before();
		}

		foreach($logicObjects as $logic) {
			$logic->handleDo();
		}

		foreach($logicObjects as $logic) {
			$logic->go();
		}

		foreach($logicObjects as $logic) {
			$logic->after();
		}
	}

	protected function injectCsrf(View $view):void {
		if($view instanceof PageView) {
			$protector = new HTMLDocumentProtector(
				$view->getViewModel(),
				$this->csrfProtection
			);
			$protector->protectAndInject();
		}
	}

	protected function httpErrorResponse(
		ServerRequestInterface $request
	):?ResponseInterface {
		$statusCode = http_response_code();
		if($statusCode < 300) {
			return null;
		}

		$request = $request->withUri(new Uri("/$statusCode"));
		$this->errorHandlingFlag = true;
		return $this->handle($request);
	}

	protected function setLogicProperties(
		AbstractLogic $logic,
		LogicPropertyStore $logicPropertyStore
	):void {
		if($logic instanceof  PageSetup
		|| $logic instanceof ApiSetup) {
			return;
		}

		$propertyStoreReader = new LogicPropertyStoreReader(
			$logicPropertyStore
		);

		foreach($propertyStoreReader as $key => $value) {
			if(in_array($key, LogicPropertyStoreReader::FORBIDDEN_LOGIC_PROPERTIES)) {
				// TODO: Throw exception (?)
				continue;
			}

			if(!property_exists($logic, $key)) {
				continue;
			}

			$logic->$key = $value;
		}
	}
}