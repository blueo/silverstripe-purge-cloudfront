# AWS setup

What the AWS side needs, and how to check it.

## Credentials

The module passes no credentials to the SDK. The AWS SDK for PHP then walks
its default provider chain, in this order:

1. The environment variables `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY`.
2. An assumed role from the shared config file, where `AWS_PROFILE` names one.
3. The shared credentials file at `~/.aws/credentials`.
4. The ECS container credentials endpoint, read from
   `AWS_CONTAINER_CREDENTIALS_RELATIVE_URI`. **This is the step that serves a
   Fargate task role.**
5. The EC2 instance metadata service.

The deployment this module targets runs on Fargate. The ECS agent sets
`AWS_CONTAINER_CREDENTIALS_RELATIVE_URI` in the task, the SDK reads it, and
the credentials rotate without anyone handling them. A key in config would
have to be rotated by hand and would sit in the environment of every process
in the task.

## The task role

The task role needs one action. Attach this policy to the role named in the
task definition's `taskRoleArn`, not to the execution role:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "PurgeTheSiteDistribution",
            "Effect": "Allow",
            "Action": "cloudfront:CreateInvalidation",
            "Resource": "arn:aws:cloudfront::123456789012:distribution/E1A2B3C4D5E6F7"
        }
    ]
}
```

A CloudFront distribution ARN carries the account id and no region, because
CloudFront is global.

Add `cloudfront:GetInvalidation` if an operator will read invalidation status
from the task. The module does not call it.

The execution role is what pulls the image and writes the logs. Putting this
statement there gives the permission to the wrong identity, and the symptom is
`AccessDenied` at the first publish.

## In CloudFormation

```yaml
TaskRole:
  Type: AWS::IAM::Role
  Properties:
    AssumeRolePolicyDocument:
      Version: '2012-10-17'
      Statement:
        - Effect: Allow
          Principal:
            Service: ecs-tasks.amazonaws.com
          Action: sts:AssumeRole
    Policies:
      - PolicyName: purge-cloudfront
        PolicyDocument:
          Version: '2012-10-17'
          Statement:
            - Effect: Allow
              Action: cloudfront:CreateInvalidation
              Resource: !Sub 'arn:aws:cloudfront::${AWS::AccountId}:distribution/${Distribution}'
```

## The environment variables

```
PURGE_CLOUDFRONT_DISTRIBUTION_ID="E1A2B3C4D5E6F7"
```

Set it in the task definition beside the other site variables. It is not a
secret, so it does not need Secrets Manager.

`PURGE_CLOUDFRONT_REGION` is optional and defaults to `us-east-1`. Set it only
to point the SDK at a local stub.

## Checking it

```bash
sake tasks:blueo-purge --capabilities
```

Reads:

```
Provider: Blueo\Purge\CloudFront\Service\CloudFrontPurgeAdaptor
  Purge by URL: yes
  Purge everything: yes
  Purge by tag: no
Queue: queuedjobs
```

`Provider: Blueo\Purge\Service\NullPurgeAdaptor` means
`PURGE_CLOUDFRONT_DISTRIBUTION_ID` is not set in that environment, because the
Injector block is gated on it.

Then send one:

```bash
sake tasks:blueo-purge --url=/
```

A success prints the invalidation id. Look it up in the CloudFront console
under the distribution, on the Invalidations tab.

## Local development

Leave `PURGE_CLOUDFRONT_DISTRIBUTION_ID` out of `.env` on a developer machine.
The provider then binds nothing, and a publish logs that no provider is
installed rather than invalidating the production distribution from a laptop.

To test against a real distribution from a machine, set `AWS_PROFILE` to a
profile that can assume a role with `cloudfront:CreateInvalidation`, and set
the distribution id to a distribution built for the purpose.

## Errors you will see

| Message | Cause |
|---|---|
| `AccessDenied: User ... is not authorized to perform: cloudfront:CreateInvalidation` | The policy is on the execution role, or the resource ARN names a different distribution. |
| `NoSuchDistribution` | `PURGE_CLOUDFRONT_DISTRIBUTION_ID` holds the domain name, `d111111abcdef8.cloudfront.net`, and not the id. |
| `TooManyInvalidationsInProgress` | More invalidations are open on the distribution than CloudFront will hold. Raise `paths_per_invalidation` so fewer requests carry the same paths, or purge less often. |
| `Unable to locate credentials` | The container credentials endpoint is not reachable. On Fargate, check that the task definition names a `taskRoleArn`. |
| `PURGE_CLOUDFRONT_DISTRIBUTION_ID is not set` | The variable is missing in an environment where the module was expected to be active. |
