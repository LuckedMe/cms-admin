<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Site;

use App\Contract\MessageHandler\CommandHandlerInterface;
use App\Exception\CommunicationException;
use App\Message\Command\Site\SiteCacheClearCommand;
use App\Repository\Site\SiteRepository;
use App\Service\Log\AppLogger;
use JsonException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function rtrim;

use const JSON_THROW_ON_ERROR;

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
            $baseUri = rtrim($command->domain, '/');
            $url     = '/lucked-me-cms-client/cache-clear';

            $response = $this->httpClient->request('POST', $url, [
                'base_uri'    => $baseUri,
                'verify_peer' => false,
                'headers'     => [
                    'Accept'    => 'application/json',
                    'X-API-KEY' => $command->token,
                ],
            ]);

            try {
                $content      = $response->getContent(false);
                $responseJson = json_encode(json_decode($content, false, 512, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $responseJson = $response->getContent(false);
            }

            $this->logger->debug('site-cache-clear', [
                'siteId'   => $this->siteRepository->findOneByToken($command->token)?->getId()?->toString(),
                'method'   => 'POST',
                'url'      => $baseUri . $url,
                'response' => $responseJson,
            ]);

            // throw on error
            $response->getContent();
        } catch (Throwable $e) {
            throw CommunicationException::createFromPrevious($e);
        }
    }
}
