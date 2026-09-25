<?php

namespace Blueo\Purge\CloudFront\Tests;

use Blueo\Purge\CloudFront\Service\CloudFrontPurgeAdaptor;
use Blueo\Purge\Service\PurgeAdaptor;
use Blueo\Purge\Service\PurgeCapability;
use Blueo\Purge\Service\PurgeService;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * The config block is gated on PURGE_CLOUDFRONT_DISTRIBUTION_ID, which the
 * test bootstrap sets. These tests assert the gate opens and the binding
 * replaces the core module's NullPurgeAdaptor.
 */
class ConfigBindingTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testTheAdaptorIsBoundOverTheCoreInterface(): void
    {
        $this->assertInstanceOf(CloudFrontPurgeAdaptor::class, Injector::inst()->get(PurgeAdaptor::class));
    }

    public function testThePurgeServiceReportsTheCloudFrontCapabilities(): void
    {
        $service = PurgeService::singleton();

        $this->assertTrue($service->supports(PurgeCapability::PurgeUrls));
        $this->assertTrue($service->supports(PurgeCapability::PurgeAll));
        $this->assertFalse($service->supports(PurgeCapability::PurgeTags));
    }

    public function testTheSdkClientIsBuiltByTheFactoryInTheRegionThatServesTheApi(): void
    {
        $client = Injector::inst()->get('Aws\CloudFront\CloudFrontClient.purgeClient');

        $this->assertSame('us-east-1', $client->getRegion());
    }
}
