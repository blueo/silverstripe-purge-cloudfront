<?php

namespace Blueo\Purge\CloudFront\Tests;

use Aws\CloudFront\CloudFrontClient;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Blueo\Purge\CloudFront\Service\CloudFrontPurgeAdaptor;
use Blueo\Purge\CloudFront\Service\PathBatcher;
use Blueo\Purge\Exception\UnsupportedOperationException;
use Blueo\Purge\Service\PurgeCapability;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

class CloudFrontPurgeAdaptorTest extends SapphireTest
{
    protected $usesDatabase = false;

    private MockHandler $handler;

    /** @var array<CommandInterface> */
    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();

        Environment::setEnv('PURGE_CLOUDFRONT_DISTRIBUTION_ID', 'E1TESTDIST');
        $this->handler = new MockHandler();
        $this->commands = [];
    }

    private function queueSuccess(string $id): void
    {
        $this->handler->append(function (CommandInterface $command) use ($id): Result {
            $this->commands[] = $command;

            return new Result(['Invalidation' => ['Id' => $id, 'Status' => 'InProgress']]);
        });
    }

    private function queueFailure(string $code, string $message): void
    {
        $this->handler->append(function (CommandInterface $command) use ($code, $message): AwsException {
            $this->commands[] = $command;

            return new AwsException($message, $command, ['code' => $code, 'message' => $message]);
        });
    }

    private function adaptor(): CloudFrontPurgeAdaptor
    {
        // The named service is what $dependencies asks for. On a site it is
        // built by ClientFactory from the environment. Here it carries a
        // handler that records the command instead of sending it.
        Injector::inst()->registerService(
            new CloudFrontClient([
                'region' => 'us-east-1',
                'version' => '2020-05-31',
                'credentials' => false,
                'handler' => $this->handler,
            ]),
            CloudFrontClient::class . '.purgeClient'
        );

        return CloudFrontPurgeAdaptor::create();
    }

    private function pathsOf(int $index): array
    {
        return $this->commands[$index]['InvalidationBatch']['Paths']['Items'];
    }

    public function testTagPurgingIsNotDeclared(): void
    {
        $adaptor = $this->adaptor();

        $this->assertTrue($adaptor->supports(PurgeCapability::PurgeUrls));
        $this->assertTrue($adaptor->supports(PurgeCapability::PurgeAll));
        $this->assertFalse($adaptor->supports(PurgeCapability::PurgeTags));
    }

    public function testATagPurgeThrowsRatherThanReportingSuccess(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->expectExceptionMessageMatches('/does not purge by tag/');

        $this->adaptor()->purgeTags(['news']);
    }

    public function testPurgeAllSendsTheWildcardPath(): void
    {
        $this->queueSuccess('I1');

        $result = $this->adaptor()->purgeAll();

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(['/*'], $this->pathsOf(0));
        $this->assertSame(1, $result->getCount());
    }

    public function testTheInvalidationIdComesBackAsTheResultReference(): void
    {
        $this->queueSuccess('I2J3K4L5M6');

        $this->assertSame('I2J3K4L5M6', $this->adaptor()->purgeUrls(['/news/'])->getReference());
    }

    public function testTheDistributionIdIsReadFromTheEnvironment(): void
    {
        $this->queueSuccess('I1');
        $this->adaptor()->purgeUrls(['/news/']);

        $this->assertSame('E1TESTDIST', $this->commands[0]['DistributionId']);
    }

    public function testEachCallCarriesItsOwnCallerReference(): void
    {
        $this->queueSuccess('I1');
        $this->queueSuccess('I2');

        PathBatcher::config()->set('paths_per_invalidation', 1);
        $this->adaptor()->purgeUrls(['/a/', '/b/']);

        $this->assertNotSame(
            $this->commands[0]['InvalidationBatch']['CallerReference'],
            $this->commands[1]['InvalidationBatch']['CallerReference']
        );
    }

    public function testMoreUrlsThanOneBatchProduceOneCallPerBatch(): void
    {
        PathBatcher::config()->set('paths_per_invalidation', 2);
        $this->queueSuccess('I1');
        $this->queueSuccess('I2');
        $this->queueSuccess('I3');

        $urls = ['/a/', '/b/', '/c/', '/d/', '/e/'];
        $result = $this->adaptor()->purgeUrls($urls);

        $this->assertCount(3, $this->commands);
        $this->assertSame(['/a/', '/b/'], $this->pathsOf(0));
        $this->assertSame(['/c/', '/d/'], $this->pathsOf(1));
        $this->assertSame(['/e/'], $this->pathsOf(2));
        $this->assertSame('I1, I2, I3', $result->getReference());
        $this->assertSame(5, $result->getCount());
    }

    public function testTheQuantityMatchesTheNumberOfPaths(): void
    {
        PathBatcher::config()->set('paths_per_invalidation', 2);
        $this->queueSuccess('I1');
        $this->queueSuccess('I2');

        $this->adaptor()->purgeUrls(['/a/', '/b/', '/c/']);

        $this->assertSame(2, $this->commands[0]['InvalidationBatch']['Paths']['Quantity']);
        $this->assertSame(1, $this->commands[1]['InvalidationBatch']['Paths']['Quantity']);
    }

    public function testAnAwsErrorBecomesAFailedResultAndNotAnException(): void
    {
        $this->queueFailure('AccessDenied', 'User is not authorized to perform cloudfront:CreateInvalidation');

        $result = $this->adaptor()->purgeUrls(['/news/']);

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('AccessDenied', $result->getMessage());
    }

    public function testThrottlingIsReportedWithItsAwsErrorCode(): void
    {
        $this->queueFailure('TooManyInvalidationsInProgress', 'Too many invalidations in progress');

        $result = $this->adaptor()->purgeUrls(['/news/']);

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('TooManyInvalidationsInProgress', $result->getMessage());
    }

    public function testOneFailedBatchFailsThePurgeAndTheOtherBatchIsStillSent(): void
    {
        PathBatcher::config()->set('paths_per_invalidation', 1);
        $this->queueSuccess('I1');
        $this->queueFailure('Throttling', 'Rate exceeded');

        $result = $this->adaptor()->purgeUrls(['/a/', '/b/']);

        $this->assertCount(2, $this->commands);
        $this->assertFalse($result->isSuccessful());
        $this->assertSame('I1', $result->getReference());
    }

    public function testAUrlCloudFrontCannotExpressFailsThePurgeAndIsNamed(): void
    {
        $this->queueSuccess('I1');

        $result = $this->adaptor()->purgeUrls(['/news/', '/~bernie/']);

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('/~bernie/', $result->getMessage());
        $this->assertSame(['/news/'], $this->pathsOf(0));
    }

    public function testAMissingDistributionIdIsAFailedResultWithTheVariableNamed(): void
    {
        Environment::setEnv('PURGE_CLOUDFRONT_DISTRIBUTION_ID', '');

        $result = $this->adaptor()->purgeAll();

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('PURGE_CLOUDFRONT_DISTRIBUTION_ID', $result->getMessage());
    }
}
