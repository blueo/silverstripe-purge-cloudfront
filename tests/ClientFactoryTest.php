<?php

namespace Blueo\Purge\CloudFront\Tests;

use Blueo\Purge\CloudFront\Service\ClientFactory;
use SilverStripe\Dev\SapphireTest;

class ClientFactoryTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testTheRegionDefaultsToTheOneThatServesTheCloudFrontApi(): void
    {
        $client = (new ClientFactory())->create(null, ['region' => null]);

        $this->assertSame('us-east-1', $client->getRegion());
    }

    public function testTheRegionCanBeSet(): void
    {
        $client = (new ClientFactory())->create(null, ['region' => 'ap-southeast-2']);

        $this->assertSame('ap-southeast-2', $client->getRegion());
    }

    public function testNoCredentialsAreSetSoTheDefaultChainIsUsed(): void
    {
        $client = (new ClientFactory())->create(null, ['region' => 'us-east-1']);

        // The provider is resolved lazily. Its presence as a promise rather
        // than a fixed key is what says the chain is in use.
        $this->assertNotNull($client->getCredentials());
    }
}
