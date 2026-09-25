<?php

namespace Blueo\Purge\CloudFront\Service;

use Aws\CloudFront\CloudFrontClient;
use SilverStripe\Core\Injector\Factory;

/**
 * Builds the CloudFront SDK client.
 *
 * Credentials are not passed. The SDK then uses its default provider chain,
 * which on Fargate reads the task role through the container credentials
 * endpoint. The task role needs cloudfront:CreateInvalidation on the
 * distribution. Keys in config would have to be rotated by hand and would sit
 * in the environment of every process on the task.
 *
 * The CloudFront API is global and is served from us-east-1. The region is
 * settable so a test or a local stub can point elsewhere.
 */
class ClientFactory implements Factory
{
    public function create(mixed $service, array $params = []): CloudFrontClient // phpcs:ignore
    {
        $config = [
            'version' => $params['version'] ?? '2020-05-31',
            'region' => $params['region'] ?: 'us-east-1',
        ];

        if (!empty($params['endpoint'])) {
            $config['endpoint'] = $params['endpoint'];
        }

        if (!empty($params['profile'])) {
            $config['profile'] = $params['profile'];
        }

        return new CloudFrontClient($config);
    }
}
