<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Site;

use App\Contract\MessageHandler\CommandHandlerInterface;
use App\Exception\CommunicationException;
use App\Message\Command\Site\SiteCacheClearCommand;
use App\Repository\Site\SiteRepository;
use App\Service\Log\AppLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function rtrim;

readonly class SiteCacheClearHandler implements CommandHandlerInterface
{
    public function __construct(
        private AppLogger $logger,
        private HttpClientInterface $httpClient,
        private SiteRepository $siteRepository,
    ) {
    }

    public function __invoke(SiteCacheClearCommand $command): void
    {
        try {
            $baseUri  = rtrim($command->domain, '/');
            $url      = '/api/cms-cache-clear';
            $response = $this->httpClient->request('POST', $url, [
                'base_uri'    => $baseUri,
                'verify_peer' => false,
                'headers'     => ['X-API-KEY' => $command->token],
            ]);

            $this->logger->debug('site-cache-clear', [
                'siteId'   => $this->siteRepository->findOneByToken($command->token)?->getId()?->toString(),
                'method'   => 'POST',
                'url'      => $baseUri . $url,
                'response' => $response->getStatusCode(),
            ]);

            // throw on error
            $response->getContent();
        } catch (Throwable $e) {
            throw CommunicationException::createFromPrevious($e);
        }
    }
}
