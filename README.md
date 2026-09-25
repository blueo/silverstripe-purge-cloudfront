# blueo/silverstripe-purge-cloudfront

Amazon CloudFront provider for
[blueo/silverstripe-purge](https://github.com/blueo/silverstripe-purge).

It binds `Blueo\Purge\CloudFront\Service\CloudFrontPurgeAdaptor` over the core
adaptor interface and sends `CreateInvalidation` to a distribution. The core
module holds the triggers, the queue and the logging. This module holds the
CloudFront rules.

* [Installation](#installation)
* [Environment](#environment)
* [Credentials and IAM](#credentials-and-iam)
* [Feature support](#feature-support)
* [Why batching is not optional](#why-batching-is-not-optional)
* [CloudFront limits](#cloudfront-limits)
* [Paths](#paths)
* [Cost](#cost)
* [Configuration](#configuration)
* [AWS setup in detail](docs/aws-setup.md)
* [Tags, and why this provider refuses them](docs/tags.md)

## Installation

```bash
composer require blueo/silverstripe-purge-cloudfront
sake dev/build flush=1
sake tasks:blueo-purge --capabilities
```

The last line prints the bound provider and what it supports. Run it on each
environment after a deployment.

## Environment

| Variable | Required | Purpose |
|---|---|---|
| `PURGE_CLOUDFRONT_DISTRIBUTION_ID` | Yes | The distribution to invalidate, for example `E1A2B3C4D5E6F7`. |
| `PURGE_CLOUDFRONT_REGION` | No | Defaults to `us-east-1`. The CloudFront API is global and is served from us-east-1. |

The Injector block in `_config/purge-cloudfront.yml` carries
`Only: envvarset: 'PURGE_CLOUDFRONT_DISTRIBUTION_ID'`. Until that variable is
set, this module binds nothing, the core module's `NullPurgeAdaptor` stays in
place, and a purge call throws and names the missing configuration. Installing
the module on a branch environment with no distribution changes nothing.

## Credentials and IAM

Credentials are not read from config and are not passed to the SDK. The AWS
SDK uses its default provider chain, which on Fargate reads the task role
through the container credentials endpoint.

The deployment this module targets runs on Fargate with a task role. The task
role needs `cloudfront:CreateInvalidation` on the distribution:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": "cloudfront:CreateInvalidation",
            "Resource": "arn:aws:cloudfront::123456789012:distribution/E1A2B3C4D5E6F7"
        }
    ]
}
```

A role without it produces a failed `PurgeResult` whose message begins
`AccessDenied`, written to the site log. See
[AWS setup in detail](docs/aws-setup.md) for local development, for a
deployment that is not on Fargate, and for the CloudFormation form of the
policy.

## Feature support

| Capability | Supported | Notes |
|---|---|---|
| Purge by URL | Yes | One `CreateInvalidation` for each batch of paths. |
| Purge everything | Yes | Sends the single path `/*`. |
| Purge by tag | **No** | `purgeTags()` throws `UnsupportedOperationException`. Ask `PurgeService::supports(PurgeCapability::PurgeTags)` first. |
| Purge by prefix | Through the URL API | Pass `/news/*` as a URL. A `*` is a wildcard for CloudFront only as the last character of the path. |
| Read the status of a purge | Not here | The result carries the invalidation id. Read the status with `GetInvalidation` or in the AWS console. |

CloudFront does have a tag invalidation feature. It works only on a
distribution that carries `CacheTagConfig`, and only for objects whose origin
response carried a cache tag header. The site cannot see either, and
CloudFront accepts a tag invalidation on a distribution that has neither. It
returns an invalidation id for work that removed nothing. Declaring the
capability would therefore be a claim about the distribution and not about
this code, and a site would read that invalidation id as a purge that
happened. The reasoning and the route to tag purging are in
[docs/tags.md](docs/tags.md).

## Why batching is not optional

`PathBatcher` normalises URLs to CloudFront paths, drops repeats, and cuts the
list into one `CreateInvalidation` for each 1,000 paths. Three reasons, and
none of them is tidiness:

1. **The quota is a rate.** CloudFront allows 150 paths or tags per second,
   and 1 wildcard invalidation per second. Paths sent one at a time are one
   API call each against that rate. A site that republishes a section sends
   hundreds of paths in a few seconds and is throttled. A request of 1,000
   paths is one call.
2. **A request has a ceiling.** CloudFront applied a limit of 3,000 paths for
   one invalidation request before the rate quota replaced it. A list built
   from a bulk publish passes 3,000 without anyone deciding to. The default
   batch of 1,000 stays under it.
3. **Repeats are charged.** Every path over 1,000 in a month is charged, and
   the charge is per path submitted, whether or not the same path is
   submitted twice. Two URLs that normalise to one path are sent once.

Batching does not reduce the bill on its own. AWS documents that each path in
a request is counted separately for billing, so 1,000 paths in one request
cost what 1,000 paths in 1,000 requests cost. Batching bounds the call rate.
Deduplication and the collector settings are what bound the cost. See
[cost](#cost).

## CloudFront limits

Measured against the CloudFront developer guide, September 2026.

| Limit | Value | How this module handles it |
|---|---|---|
| Invalidation rate | 150 paths or tags per second | Batches of 1,000 paths in one call, so the rate is spent on paths and not on request overhead. `paths_per_invalidation` is config. |
| Wildcard invalidation rate | 1 per second | `purgeAll()` sends one path, `/*`. Call it once for each deployment, not for each page. |
| Paths in one request | No published quota. CloudFront applied 3,000 before the rate quota replaced it. | The default batch of 1,000 stays under the older ceiling. |
| In-progress invalidations | Not published as a quota. CloudFront returns `TooManyInvalidationsInProgress` under load. | The error code reaches the `PurgeResult` message and the log. Under queuedjobs the job records the failure and does not retry, because a throttled path will be throttled again. |
| Maximum length of a path | 4,000 characters | A longer path is refused, named in the failed result and written to the log. It is not dropped. |
| Free invalidation paths | 1,000 for each month, for the whole AWS account | Repeats are removed before submission. The `PurgeResult` count is the number of paths submitted, so a site can measure what it spends. |

## Paths

CloudFront path rules, and what this module does with them:

| Rule | Handling |
|---|---|
| A path is relative to the distribution and starts with `/` | An absolute URL is reduced to its path. A leading slash is added. |
| Paths are case sensitive | Case is never changed. |
| `*` is a wildcard only as the last character | Passed through. `/news/*` is a prefix purge. `/a*b` matches a literal asterisk, which is CloudFront's rule and not this module's. |
| Non-ASCII and RFC 1738 unsafe characters must be encoded, and nothing else may be | Those characters are encoded byte by byte. An already encoded path is left alone, because `%` is not in the unsafe set. |
| `~` is not accepted, encoded or not | The path is refused and named. |
| A query string is part of the path where the distribution caches on query strings | Kept. |

A URL that cannot become a path fails the whole purge and is named in the
result message and in the log. It is not dropped. A dropped path is a page
that stays stale with nothing to read about it.

## Cost

The first 1,000 invalidation paths in a month are free, across the whole AWS
account and not per distribution. Each path after that is charged. A wildcard
path counts as one path however many files it removes.

The default triggers in the core module send three paths for each publish: the
page, its parent and the home page. A site that publishes 300 times a month is
at 900 paths and is inside the allowance. A site that publishes 3,000 times is
at 9,000 paths.

Three ways to spend less, in the order to try them:

1. Turn `include_home` off in `Blueo\Purge\Service\UrlCollector` if the home
   page does not list recent content.
2. Turn the publish triggers off and run `sake tasks:blueo-purge --all` at the
   end of a deployment. That is one path for each deployment. The cost is that
   the whole cache refills from the origin.
3. Raise the cache time and accept the wait, so the purge matters less.

## Configuration

```yaml
Blueo\Purge\CloudFront\Service\PathBatcher:
  # Paths in one CreateInvalidation call.
  paths_per_invalidation: 1000

Blueo\Purge\CloudFront\Service\CloudFrontPurgeAdaptor:
  # Read the distribution id from a different variable, for a site that
  # already names it something else.
  distribution_id_env_var: 'PURGE_CLOUDFRONT_DISTRIBUTION_ID'
```

## Tests

```bash
composer install
vendor/bin/phpunit
```

The tests run from the module directory against SQLite and a mocked AWS
handler. No AWS credentials are read and no request leaves the machine.

`composer.json` carries a path repository pointing at `../silverstripe-purge`,
so the two modules can be developed side by side. Remove it once both are
published.
