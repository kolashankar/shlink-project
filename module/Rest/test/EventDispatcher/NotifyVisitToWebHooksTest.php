<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Rest\EventDispatcher;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Fig\Http\Message\RequestMethodInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use Shlinkio\Shlink\Core\Entity\ShortUrl;
use Shlinkio\Shlink\Core\Entity\Visit;
use Shlinkio\Shlink\Core\EventDispatcher\NotifyVisitToWebHooks;
use Shlinkio\Shlink\Core\EventDispatcher\ShortUrlLocated;
use Shlinkio\Shlink\Core\Model\Visitor;
use Shlinkio\Shlink\Core\Options\AppOptions;

use function count;
use function Functional\contains;

class NotifyVisitToWebHooksTest extends TestCase
{
    /** @var ObjectProphecy */
    private $httpClient;
    /** @var ObjectProphecy */
    private $em;
    /** @var ObjectProphecy */
    private $logger;

    public function setUp(): void
    {
        $this->httpClient = $this->prophesize(ClientInterface::class);
        $this->em = $this->prophesize(EntityManagerInterface::class);
        $this->logger = $this->prophesize(LoggerInterface::class);
    }

    /** @test */
    public function emptyWebhooksMakeNoFurtherActions(): void
    {
        $find = $this->em->find(Visit::class, '1')->willReturn(null);

        $this->createListener([])(new ShortUrlLocated('1'));

        $find->shouldNotHaveBeenCalled();
    }

    /** @test */
    public function invalidVisitDoesNotPerformAnyRequest(): void
    {
        $find = $this->em->find(Visit::class, '1')->willReturn(null);
        $requestAsync = $this->httpClient->requestAsync(
            RequestMethodInterface::METHOD_POST,
            Argument::type('string'),
            Argument::type('array')
        )->willReturn(new FulfilledPromise(''));
        $logWarning = $this->logger->warning(
            'Tried to notify webhooks for visit with id "{visitId}", but it does not exist.',
            ['visitId' => '1']
        );

        $this->createListener(['foo', 'bar'])(new ShortUrlLocated('1'));

        $find->shouldHaveBeenCalledOnce();
        $logWarning->shouldHaveBeenCalledOnce();
        $requestAsync->shouldNotHaveBeenCalled();
    }

    /** @test */
    public function expectedRequestsArePerformedToWebhooks(): void
    {
        $webhooks = ['foo', 'invalid', 'bar', 'baz'];
        $invalidWebhooks = ['invalid', 'baz'];

        $find = $this->em->find(Visit::class, '1')->willReturn(new Visit(new ShortUrl(''), Visitor::emptyInstance()));
        $requestAsync = $this->httpClient->requestAsync(
            RequestMethodInterface::METHOD_POST,
            Argument::type('string'),
            Argument::type('array')
        )->will(function (array $args) use ($invalidWebhooks) {
            [, $webhook] = $args;
            $e = new Exception('');

            return contains($invalidWebhooks, $webhook) ? new RejectedPromise($e) : new FulfilledPromise('');
        });
        $logWarning = $this->logger->warning(
            'Failed to notify visit with id "{visitId}" to webhook "{webhook}". {e}',
            Argument::type('array')
        );

        $this->createListener($webhooks)(new ShortUrlLocated('1'));

        $find->shouldHaveBeenCalledOnce();
        $requestAsync->shouldHaveBeenCalledTimes(count($webhooks));
        $logWarning->shouldHaveBeenCalledTimes(count($invalidWebhooks));
    }

    private function createListener(array $webhooks): NotifyVisitToWebHooks
    {
        return new NotifyVisitToWebHooks(
            $this->httpClient->reveal(),
            $this->em->reveal(),
            $this->logger->reveal(),
            $webhooks,
            [],
            new AppOptions()
        );
    }
}
