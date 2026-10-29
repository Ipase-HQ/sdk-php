<?php

declare(strict_types=1);

namespace Ipase;

/**
 * Identity of this build. tests/Unit/VersionTest.php keeps SPEC_VERSION equal to
 * composer.json extra.ipase.spec, which CI uses to pick the mock server image.
 */
final class Version
{
    /** The SDK name in X-SDK and context.sdk.name. */
    public const SDK_NAME = 'php';

    /** This package's semver, in X-SDK and context.sdk.version. */
    public const SDK_VERSION = '0.1.0';

    /** The sdk-spec VERSION this build implements, in context.sdk.spec. */
    public const SPEC_VERSION = '1.0.0-draft.4';

    /**
     * Default ingest host until the product domain is registered. The .invalid TLD never
     * resolves, so an app that forgets the endpoint option sends nothing anywhere instead of
     * to a guessed domain. Replace this one constant when the in. hostname is settled.
     */
    public const DEFAULT_ENDPOINT = 'https://in.ipase.invalid';
}
