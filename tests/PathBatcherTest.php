<?php

namespace Blueo\Purge\CloudFront\Tests;

use Blueo\Purge\CloudFront\Service\PathBatcher;
use InvalidArgumentException;
use SilverStripe\Dev\SapphireTest;

class PathBatcherTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testARelativeLinkKeepsItsLeadingSlash(): void
    {
        $this->assertSame('/news/budget-2026/', PathBatcher::create()->toPath('/news/budget-2026/'));
    }

    public function testALeadingSlashIsAdded(): void
    {
        $this->assertSame('/news/', PathBatcher::create()->toPath('news/'));
    }

    public function testAnAbsoluteUrlIsReducedToAPath(): void
    {
        $this->assertSame('/news/', PathBatcher::create()->toPath('https://www.example.com/news/'));
    }

    public function testAHostWithNoPathBecomesTheRoot(): void
    {
        $this->assertSame('/', PathBatcher::create()->toPath('https://www.example.com'));
    }

    public function testAQueryStringIsKeptBecauseACacheKeysOnIt(): void
    {
        $this->assertSame(
            '/search/?q=budget',
            PathBatcher::create()->toPath('https://www.example.com/search/?q=budget')
        );
    }

    public function testCaseIsNotChanged(): void
    {
        $this->assertSame('/News/Budget.PDF', PathBatcher::create()->toPath('/News/Budget.PDF'));
    }

    public function testASpaceIsEncoded(): void
    {
        $this->assertSame('/news/budget%202026/', PathBatcher::create()->toPath('/news/budget 2026/'));
    }

    public function testANonAsciiCharacterIsEncoded(): void
    {
        $this->assertSame('/n%C4%81ku/', PathBatcher::create()->toPath('/nāku/'));
    }

    public function testAnAlreadyEncodedPathIsNotEncodedAgain(): void
    {
        $this->assertSame('/n%C4%81ku/', PathBatcher::create()->toPath('/n%C4%81ku/'));
    }

    public function testATrailingWildcardSurvives(): void
    {
        $this->assertSame('/news/*', PathBatcher::create()->toPath('/news/*'));
    }

    public function testATildeIsRefusedBecauseCloudFrontDoesNotAcceptOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not accept/');

        PathBatcher::create()->toPath('/~bernie/');
    }

    public function testAPathOverTheLengthLimitIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/4000/');

        PathBatcher::create()->toPath('/' . str_repeat('a', 4001));
    }

    public function testAnEmptyUrlIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PathBatcher::create()->toPath('   ');
    }

    public function testBatchingReturnsOneBatchWhenTheListIsShort(): void
    {
        $batched = PathBatcher::create()->batch(['/a/', '/b/']);

        $this->assertSame([['/a/', '/b/']], $batched['batches']);
        $this->assertSame([], $batched['rejected']);
    }

    public function testBatchesAreCutAtTheConfiguredSize(): void
    {
        PathBatcher::config()->set('paths_per_invalidation', 1000);

        $urls = [];

        for ($i = 0; $i < 2345; $i++) {
            $urls[] = sprintf('/page-%d/', $i);
        }

        $batched = PathBatcher::create()->batch($urls);

        $this->assertCount(3, $batched['batches']);
        $this->assertCount(1000, $batched['batches'][0]);
        $this->assertCount(1000, $batched['batches'][1]);
        $this->assertCount(345, $batched['batches'][2]);
    }

    public function testTheBatchSizeIsConfigurable(): void
    {
        PathBatcher::config()->set('paths_per_invalidation', 2);

        $batched = PathBatcher::create()->batch(['/a/', '/b/', '/c/']);

        $this->assertSame([['/a/', '/b/'], ['/c/']], $batched['batches']);
    }

    public function testTwoUrlsThatNormaliseToOnePathAreSubmittedOnce(): void
    {
        $batched = PathBatcher::create()->batch(['https://www.example.com/news/', '/news/', 'news/']);

        $this->assertSame([['/news/']], $batched['batches']);
    }

    public function testARejectedUrlIsNamedAndTheRestAreStillBatched(): void
    {
        $batched = PathBatcher::create()->batch(['/news/', '/~bernie/']);

        $this->assertSame([['/news/']], $batched['batches']);
        $this->assertArrayHasKey('/~bernie/', $batched['rejected']);
    }

    public function testNoUrlsProduceNoBatches(): void
    {
        $this->assertSame([], PathBatcher::create()->batch([])['batches']);
    }
}
