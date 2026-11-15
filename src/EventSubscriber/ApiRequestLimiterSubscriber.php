<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

use function max;
use function str_starts_with;
use function time;

readonly class ApiRequestLimiterSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RateLimiterFactoryInterface $apiRequestLimiter,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        // checks the /api/ prefix, not ApiRequestDetector: that also matches admin XHR and /admin/api/cms-embeds
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $limit = $this->apiRequestLimiter->create($request->getClientIp() ?? 'unknown')->consume();

        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Too many requests.');
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // before the router (32) and the firewall (8), so rejected requests stay cheap
            KernelEvents::REQUEST => ['onKernelRequest', 64],
        ];
    }
}
