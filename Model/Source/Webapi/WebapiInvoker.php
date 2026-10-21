<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\Webapi;

use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Model\Service\ServiceLocatorInterface;
use Hryvinskyi\McpServer\Model\Source\Webapi\Metadata\MetadataReaderInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Webapi\ServiceInputProcessor;
use Magento\Framework\Webapi\ServiceOutputProcessor;
use Magento\Framework\Webapi\Validator\EntityArrayValidator\InputArraySizeLimitValue;
use Magento\Webapi\Controller\Rest\ParamsOverrider;
use Psr\Log\LoggerInterface;

/**
 * Calls the service method behind a Web API route the way Magento's synchronous REST processor does
 *
 * 1. A route Magento declares secure runs only when the current request came over HTTPS; otherwise a
 *    LocalizedException says so. A transport without an HTTP request (a command line) has no such check.
 * 2. The route's input array size limit is set, as Magento sets it for a REST call of that route.
 * 3. On an update route (PUT) whose URL names the record by a path parameter the method does not take, that
 *    argument is moved into the record the method saves, as REST moves the URL's value
 *    (ParamsOverrider::overrideRequestBodyIdWithPathParam()). Only the last such parameter is moved, as in REST.
 * 4. The route's parameters are applied to the arguments: forced ones replace what the caller sent, the others fill in
 *    what is missing.
 * 5. The arguments become the method's parameters through the service input processor; invalid input throws its
 *    LocalizedException, meant for the caller.
 * 6. The service comes from the service locator and the method is called. A \TypeError from the call means the
 *    arguments do not fit the method: it becomes a LocalizedException saying so, and the type error itself goes to the
 *    log only.
 * 7. The return value goes through the service output processor, as a REST answer does.
 *
 * The call runs inside the request that carries the MCP message, not inside a REST request for the route. Code that
 * reads the current REST request (its path parameters, its body) sees that request, not the route's; and the request
 * checks Magento runs on a REST call (rate limiting, CAPTCHA for the Web API) ran once, for that request.
 */
class WebapiInvoker
{
    /**
     * @param ParamsOverrider $paramsOverrider
     * @param ServiceInputProcessor $serviceInputProcessor
     * @param ServiceOutputProcessor $serviceOutputProcessor
     * @param ServiceLocatorInterface $serviceLocator
     * @param InputArraySizeLimitValue $inputArraySizeLimit
     * @param LoggerInterface $logger
     * @param RequestInterface $request
     * @param MetadataReaderInterface $metadata
     */
    public function __construct(
        private readonly ParamsOverrider $paramsOverrider,
        private readonly ServiceInputProcessor $serviceInputProcessor,
        private readonly ServiceOutputProcessor $serviceOutputProcessor,
        private readonly ServiceLocatorInterface $serviceLocator,
        private readonly InputArraySizeLimitValue $inputArraySizeLimit,
        private readonly LoggerInterface $logger,
        private readonly RequestInterface $request,
        private readonly MetadataReaderInterface $metadata
    ) {
    }

    /**
     * Call the route's service method with these arguments and return its output as REST would answer it
     *
     * @param AbilityInterface $ability
     * @param Route $route
     * @param array<string,mixed> $arguments
     * @return mixed
     * @throws LocalizedException When the arguments do not suit the method
     * @throws \Throwable When the service fails
     */
    public function invoke(AbilityInterface $ability, Route $route, array $arguments): mixed
    {
        $class = $route->getServiceClass();
        $method = $route->getServiceMethod();

        if ($route->isSecure() && $this->request instanceof HttpRequest && !$this->request->isSecure()) {
            throw new LocalizedException(__('This ability must be called over HTTPS.'));
        }

        $this->inputArraySizeLimit->set($route->getInputArraySizeLimit());
        $input = $this->paramsOverrider->override($this->withPathIds($route, $arguments), $route->getParameters());
        $parameters = $this->serviceInputProcessor->process($class, $method, $input);

        $operation = [$this->serviceLocator->get($class), $method];
        if (!is_callable($operation)) {
            throw new LocalizedException(
                __('The ability "%1" cannot be called: its service has no method %2.', $ability->getName(), $method)
            );
        }

        try {
            $output = $operation(...array_values($parameters));
        } catch (\TypeError $error) {
            $this->logger->warning(
                sprintf(
                    'MCP ability "%s" was called with arguments it does not take: %s',
                    $ability->getName(),
                    $error->getMessage()
                ),
                ['exception' => $error]
            );

            throw new LocalizedException(
                __('The arguments do not match the parameters of the ability "%1".', $ability->getName())
            );
        }

        return $this->serviceOutputProcessor->process($output, $class, $method);
    }

    /**
     * The arguments with the record id of an update route moved from the top level into the record it saves
     *
     * @param Route $route
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    private function withPathIds(Route $route, array $arguments): array
    {
        $method = $this->metadata->methodOf(
            $route->getServiceClass(),
            $route->getServiceMethod(),
            $route->getVersion()
        );
        if ($method === null) {
            return $arguments;
        }

        $pathIds = [];
        foreach ($route->pathIdNames(array_keys($method['parameters'])) as $name) {
            if (array_key_exists($name, $arguments)) {
                $pathIds[$name] = $arguments[$name];
                unset($arguments[$name]);
            }
        }
        if ($pathIds === []) {
            return $arguments;
        }

        $moved = $this->paramsOverrider->overrideRequestBodyIdWithPathParam(
            $pathIds,
            $arguments,
            $route->getServiceClass(),
            $route->getServiceMethod()
        );

        $result = [];
        foreach ($moved as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
