<?php

declare(strict_types=1);

namespace Kreait\Firebase\Tests\Integration;

use Beste\Json;
use Kreait\Firebase\Contract\RemoteConfig;
use Kreait\Firebase\Exception\RemoteConfig\ValidationFailed;
use Kreait\Firebase\Exception\RemoteConfig\VersionMismatch;
use Kreait\Firebase\Exception\RemoteConfig\VersionNotFound;
use Kreait\Firebase\RemoteConfig\FindVersions;
use Kreait\Firebase\RemoteConfig\Parameter;
use Kreait\Firebase\RemoteConfig\Template;
use Kreait\Firebase\RemoteConfig\UpdateOrigin;
use Kreait\Firebase\RemoteConfig\UpdateType;
use Kreait\Firebase\RemoteConfig\Version;
use Kreait\Firebase\RemoteConfig\VersionNumber;
use Kreait\Firebase\Tests\IntegrationTestCase;
use Throwable;

/**
 * @internal
 */
final class RemoteConfigTest extends IntegrationTestCase
{
    /**
     * @var string
     */
    private const TEMPLATE_CONFIG = <<<'CONFIG'
        {
            "conditions": [
                {
                    "name": "lang_german",
                    "expression": "device.language in ['de', 'de_AT', 'de_CH']",
                    "tagColor": "ORANGE"
                },
                {
                    "name": "lang_french",
                    "expression": "device.language in ['fr', 'fr_CA', 'fr_CH']",
                    "tagColor": "GREEN"
                }
            ],
            "parameters": {
                "welcome_message": {
                    "defaultValue": {
                        "value": "Welcome!"
                    },
                    "conditionalValues": {
                        "lang_german": {
                            "value": "Willkommen!"
                        },
                        "lang_french": {
                            "value": "Bienvenu!"
                        }
                    },
                    "description": "This is a welcome message"
                }
            },
            "parameterGroups": {
                "welcome_messages": {
                    "description": "A group of parameters",
                    "parameters": {
                        "welcome_message_new_users": {
                            "defaultValue": {
                                "value": "Welcome, new user!"
                            },
                            "conditionalValues": {
                                "lang_german": {
                                    "value": "Willkommen, neuer Benutzer!"
                                },
                                "lang_french": {
                                    "value": "Bienvenu, nouvel utilisateur!"
                                }
                            },
                            "description": "This is a welcome message for new users"
                        },
                        "welcome_message_existing_users": {
                            "defaultValue": {
                                "value": "Welcome, existing user!"
                            },
                            "conditionalValues": {
                                "lang_german": {
                                    "value": "Willkommen, bestehender Benutzer!"
                                },
                                "lang_french": {
                                    "value": "Bienvenu, utilisant existant!"
                                }
                            },
                            "description": "This is a welcome message for existing users"
                        }
                    }
                }
            }
        }
        CONFIG;
    private Template $template;
    private RemoteConfig $remoteConfig;

    protected function setUp(): void
    {
        $this->remoteConfig = self::$factory->createRemoteConfig();
        $this->template = Template::fromArray(Json::decode(self::TEMPLATE_CONFIG, true));
    }

    public function testForcePublishAndGet(): void
    {
        $this->remoteConfig->publish($this->template);

        $check = $this->remoteConfig->get();

        self::assertEquals($this->template->jsonSerialize(), $check->jsonSerialize());

        $version = $check->version();

        if (!$version instanceof Version) {
            self::fail('The template has no version');
        }

        self::assertTrue($version->updateType()->equalsTo(UpdateType::FORCED_UPDATE));
        self::assertTrue($version->updateOrigin()->equalsTo(UpdateOrigin::REST_API));
    }

    public function testPublishOutdatedConfig(): void
    {
        $this->remoteConfig->publish($this->template);

        $published = $this->remoteConfig->get();

        $this->remoteConfig->publish($published);

        // The published template has now a different etag, so publishing it again
        // with our old etag value should fail
        $this->expectException(VersionMismatch::class);
        $this->remoteConfig->publish($published);
    }

    public function testValidateValidTemplate(): void
    {
        $this->remoteConfig->validate($this->template);
        $this->addToAssertionCount(1);
    }

    public function testValidateInvalidTemplate(): void
    {
        $template = $this->templateWithTooManyParameters();

        $this->expectException(ValidationFailed::class);
        $this->remoteConfig->validate($template);
    }

    public function testPublishInvalidTemplate(): void
    {
        $version = $this->remoteConfig->get()->version();

        if (!$version instanceof Version) {
            self::fail('The template has no version');
        }

        $currentVersionNumber = $version->versionNumber();

        $template = $this->templateWithTooManyParameters();

        try {
            $this->remoteConfig->validate($template);
            self::fail('A ' . ValidationFailed::class . ' should have been thrown');
        } catch (Throwable $e) {
            self::assertInstanceOf(ValidationFailed::class, $e);
        }

        $refetchedVersion = $this->remoteConfig->get()->version();

        if (!$refetchedVersion instanceof Version) {
            self::fail('The template has no version');
        }

        self::assertTrue(
            $currentVersionNumber->equalsTo($refetchedVersion->versionNumber()),
            "Expected the template version to be {$currentVersionNumber}, got {$refetchedVersion->versionNumber()}",
        );
    }

    public function testRollback(): void
    {
        $initialVersion = $this->remoteConfig->get()->version();

        if (!$initialVersion instanceof Version) {
            self::fail('The template has no version');
        }

        $initialVersionNumber = $initialVersion->versionNumber();

        $query = FindVersions::all()
            ->withLimit(2)
            ->upToVersion($initialVersionNumber);

        $targetVersionNumber = null;

        foreach ($this->remoteConfig->listVersions($query) as $version) {
            $versionNumber = $version->versionNumber();

            if (!$versionNumber->equalsTo($initialVersionNumber)) {
                $targetVersionNumber = $versionNumber;
            }
        }

        if ($targetVersionNumber === null) {
            self::fail('A previous version number should have been retrieved');
        }

        $this->remoteConfig->rollbackToVersion($targetVersionNumber);

        $newVersion = $this->remoteConfig->get()->version();

        if (!$newVersion instanceof Version) {
            self::fail('The new template has no version');
        }

        $newVersionNumber = $newVersion->versionNumber();
        $rollbackSource = $newVersion->rollbackSource();

        if (!$rollbackSource instanceof VersionNumber) {
            self::fail('The new template version has no rollback source');
        }

        self::assertFalse($newVersionNumber->equalsTo($initialVersionNumber));
        self::assertTrue($rollbackSource->equalsTo($targetVersionNumber));
    }

    public function testListVersionsWithoutFilters(): void
    {
        // We only need to know that the first returned value is a version,
        // no need to iterate through all of them
        foreach ($this->remoteConfig->listVersions() as $version) {
            self::assertInstanceOf(Version::class, $version);

            return;
        }

        self::fail('Expected a version to be returned, but got none');
    }

    public function testFindVersionsWithFilters(): void
    {
        $currentVersion = $this->remoteConfig->get()->version();

        if (!$currentVersion instanceof Version) {
            self::fail('The new template has no version');
        }

        $currentVersionUpdateDate = $currentVersion->updatedAt();

        $query = [
            'startingAt' => $currentVersionUpdateDate->modify('-2 months'),
            'endingAt' => $currentVersionUpdateDate,
            'upToVersion' => $currentVersion->versionNumber(),
            'pageSize' => 1,
            'limit' => $limit = 2,
        ];

        $counter = 0;

        foreach ($this->remoteConfig->listVersions($query) as $version) {
            ++$counter;

            // Protect us from an infinite loop
            if ($counter > $limit) {
                self::fail('The query returned more values than expected');
            }
        }

        self::assertLessThanOrEqual($limit, $counter);
    }

    public function testGetVersion(): void
    {
        $currentVersion = $this->remoteConfig->get()->version();

        if (!$currentVersion instanceof Version) {
            self::fail('The template has no version');
        }

        $currentVersionNumber = $currentVersion->versionNumber();

        $check = $this->remoteConfig->getVersion($currentVersionNumber);

        self::assertTrue($check->versionNumber()->equalsTo($currentVersionNumber));
    }

    public function testGetNonExistingVersion(): void
    {
        $currentVersion = $this->remoteConfig->get()->version();

        if (!$currentVersion instanceof Version) {
            self::fail('The template has no version');
        }

        $nextButNonExisting = (int) (string) $currentVersion->versionNumber() + 100;

        $this->expectException(VersionNotFound::class);
        $this->remoteConfig->getVersion($nextButNonExisting);
    }

    public function testValidateEmptyTemplate(): void
    {
        $this->remoteConfig->validate(Template::new());
        $this->addToAssertionCount(1);
    }

    private function templateWithTooManyParameters(): Template
    {
        $template = Template::new();

        for ($i = 0; $i < 3001; ++$i) {
            $template = $template->withParameter(Parameter::named('i_' . $i, 'v_' . $i));
        }

        return $template;
    }
}
