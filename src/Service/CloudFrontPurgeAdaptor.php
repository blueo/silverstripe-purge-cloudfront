<?php

namespace Blueo\Purge\CloudFront\Service;

use Aws\CloudFront\CloudFrontClient;
use Aws\Exception\AwsException;
use Blueo\Purge\Exception\UnsupportedOperationException;
use Blueo\Purge\Service\PurgeAdaptor;
use Blueo\Purge\Service\PurgeCapability;
use Blueo\Purge\Service\PurgeResult;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use Throwable;

/**
 * Purges a CloudFront distribution with CreateInvalidation.
 *
 * This adaptor declares PurgeUrls and PurgeAll. It does not declare PurgeTags,
 * and a call to purgeTags throws. CloudFront tag invalidation works only on a
 * distribution that carries CacheTagConfig, and the site cannot read that.
 * Declaring the capability would be a claim about the distribution rather than
 * about this code, and a tag sent to a distribution without CacheTagConfig
 * returns an invalidation id for work that removed nothing. See docs/tags.md.
 *
 * The distribution id is read from an environment variable, because it differs
 * between a production stack and a staging stack that share a codebase. The
 * config block that binds this class is gated on the same variable, so the
 * module is inert until it is set.
 */
class CloudFrontPurgeAdaptor implements PurgeAdaptor
{
    use Injectable;
    use Configurable;

    /**
     * Where the distribution id is read from. The Injector block in
     * _config/purge-cloudfront.yml is gated on this same name.
     */
    private static string $distribution_id_env_var = 'PURGE_CLOUDFRONT_DISTRIBUTION_ID';

    /**
     * The path that invalidates every file in the distribution. It counts as
     * one path against the free monthly allowance, whatever it removes.
     */
    public const WILDCARD_ALL = '/*';

    private ?CloudFrontClient $client = null;

    private ?LoggerInterface $logger = null;

    private ?PathBatcher $batcher = null;

    private static array $dependencies = [
        'client' => '%$' . CloudFrontClient::class . '.purgeClient',
        'logger' => '%$' . LoggerInterface::class,
        'batcher' => '%$' . PathBatcher::class,
    ];

    public function setClient(?CloudFrontClient $client): void
    {
        $this->client = $client;
    }

    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function setBatcher(?PathBatcher $batcher): void
    {
        $this->batcher = $batcher;
    }

    public function supports(PurgeCapability $capability): bool
    {
        return match ($capability) {
            PurgeCapability::PurgeUrls, PurgeCapability::PurgeAll => true,
            PurgeCapability::PurgeTags => false,
        };
    }

    public function purgeUrls(array $urls): PurgeResult
    {
        $batcher = $this->batcher ?? PathBatcher::singleton();
        ['batches' => $batches, 'rejected' => $rejected] = $batcher->batch($urls);

        $results = [];

        foreach ($batches as $paths) {
            $results[] = $this->invalidate($paths);
        }

        $result = PurgeResult::combine($results);

        if (!$rejected) {
            return $result;
        }

        // A rejected path is reported as a failure even when every other path
        // went through, because the page behind it stays stale.
        foreach ($rejected as $url => $reason) {
            $this->logger?->error(sprintf('CloudFront purge rejected %s: %s', $url, $reason));
        }

        return PurgeResult::failure(
            trim(sprintf(
                '%s %d URL(s) were not valid invalidation paths: %s',
                $result->getMessage(),
                count($rejected),
                implode('; ', array_keys($rejected))
            )),
            $result->getReference(),
            $result->getCount()
        );
    }

    public function purgeAll(): PurgeResult
    {
        return $this->invalidate([self::WILDCARD_ALL]);
    }

    public function purgeTags(array $tags): PurgeResult
    {
        throw new UnsupportedOperationException(
            'This provider does not purge by tag. CloudFront tag invalidation needs CacheTagConfig on the '
                . 'distribution and a cache tag header on every origin response, and neither is visible from '
                . 'the site. See docs/tags.md. '
                . 'Ask PurgeService::supports(PurgeCapability::PurgeTags) before you call it.'
        );
    }

    public function getDistributionId(): string
    {
        $name = (string) $this->config()->get('distribution_id_env_var');
        $id = (string) Environment::getEnv($name);

        if ($id === '') {
            throw new RuntimeException(sprintf('%s is not set, so there is no distribution to purge.', $name));
        }

        return $id;
    }

    /**
     * One CreateInvalidation call.
     *
     * @param array<string> $paths
     */
    private function invalidate(array $paths): PurgeResult
    {
        if (!$paths) {
            return PurgeResult::success(null, 'Nothing to purge.');
        }

        try {
            $response = $this->client->createInvalidation([
                'DistributionId' => $this->getDistributionId(),
                'InvalidationBatch' => [
                    // CloudFront uses the caller reference to recognise a
                    // repeat of a request it already has.
                    'CallerReference' => uniqid('blueo-purge-', true),
                    'Paths' => [
                        'Quantity' => count($paths),
                        'Items' => array_values($paths),
                    ],
                ],
            ]);

            $id = $response['Invalidation']['Id'] ?? null;

            return PurgeResult::success($id === null ? null : (string) $id, '', count($paths));
        } catch (AwsException $e) {
            $message = sprintf('%s: %s', (string) $e->getAwsErrorCode(), $e->getAwsErrorMessage() ?? $e->getMessage());
            $this->logger?->error(sprintf('CloudFront invalidation failed. %s', $message), ['exception' => $e]);

            return PurgeResult::failure($message, null, count($paths));
        } catch (Throwable $e) {
            $this->logger?->error(
                sprintf('CloudFront invalidation failed. %s', $e->getMessage()),
                ['exception' => $e]
            );

            return PurgeResult::failure($e->getMessage(), null, count($paths));
        }
    }
}
