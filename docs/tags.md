# Tags, and why this provider refuses them

`Blueo\Purge\Service\PurgeCapability::PurgeTags` is declared false by this
provider. `purgeTags()` throws `UnsupportedOperationException`.

## What CloudFront offers

CloudFront can invalidate by cache tag. It works like this:

1. The distribution carries a `CacheTagConfig` naming an HTTP header, for
   example `x-amz-meta-cache-tag`.
2. Every origin response that should carry tags includes that header, with the
   tags comma separated. CloudFront stores up to 50 tags for each object.
3. `CreateInvalidation` is called with a path that starts with `#`, for
   example `#news`. Every cached object that carries the tag is invalidated,
   whatever its path.

Tag paths and URL paths can be mixed in one request. A tag counts as one path
against the free monthly allowance of 1,000, the same as `/*`.

## Why this provider does not declare the capability

Three of the three preconditions live outside the site:

* `CacheTagConfig` is set on the distribution. The site cannot read it without
  `cloudfront:GetDistributionConfig`, which the task role does not have.
* The origin must send a cache tag header on every response to be tagged. A
  Silverstripe site does not send one, and adding one is a decision about the
  response headers of every page.
* The tags must mean something. A tag is only useful if the site decided what
  it tags with, and that is site design and not module behaviour.

And the failure mode is the one this design exists to prevent. CloudFront
accepts `#news` on a distribution with no `CacheTagConfig`. It returns an
invalidation id and a status. Nothing is removed, because nothing was ever
tagged. A provider that declared the capability would hand back a successful
`PurgeResult` with an invalidation id, and the site would record a purge that
did not happen.

A capability is a claim about what the code does. `CacheTagConfig` is a fact
about a distribution. Declaring one as the other is how a site ends up
believing it purged.

## What to do instead

**Purge the URLs.** Where a site knows which pages carry a term, collect them
and purge by URL. Replace `Blueo\Purge\Service\UrlCollector` and return the
pages a change affects. This is exact, and it costs one path for each page.

**Purge a prefix.** Where a section shares a path, pass a wildcard as a URL:

```php
PurgeService::singleton()->purgeUrls(['/news/*']);
```

One path against the allowance, whatever it removes. The `*` is a wildcard for
CloudFront only as the last character of the path, and CloudFront allows 1
wildcard invalidation per second.

**Purge everything.** After a deployment that changed a template, `/*` is one
path and removes everything.

## If you want tag invalidation

It is a subclass and a config block, not a change to this module:

1. Add `CacheTagConfig` to the distribution, naming a header.
2. Make the site send that header. A response header policy or a Lambda@Edge
   origin response function can add it, or the site can send it from a
   Silverstripe `HTTPMiddleware`.
3. Subclass `CloudFrontPurgeAdaptor`. Return true from `supports()` for
   `PurgeCapability::PurgeTags`, and send each tag as `#` plus the tag through
   the same `CreateInvalidation` call.
4. Bind the subclass over `Blueo\Purge\Service\PurgeAdaptor` in your project
   config, after the module's block.

The capability is then declared by the code that knows the distribution is
configured for it, which is your project and not this module.
