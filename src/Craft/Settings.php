<?php

namespace facilo\rumbo\Craft;

use craft\base\Model;
use craft\helpers\App;

class Settings extends Model
{
    public const PROVIDERS = ['openfreemap', 'google', 'mapbox', 'maptiler', 'here', 'apple'];
    public const GEOCODERS = ['nominatim', 'google', 'mapbox', 'maptiler', 'here', 'apple'];
    public const STYLES = ['liberty', 'bright', 'positron', 'dark'];
    public const LOOKS = ['side', 'mapFirst', 'list'];

    // Maps and search
    public string $mapProvider = 'openfreemap';
    public string $mapStyle = 'positron';
    public string $geocoder = 'nominatim';
    public string $nominatimUrl = '';
    public string $photonUrl = '';
    /**
     * Per provider: browser key (used on pages), server key (address lookups), and extras:
     * google secret (URL signing) and mapId; apple teamId, keyId and privateKey (an env var holding the .p8 text).
     * @var array<string, array{browser?:string, server?:string, secret?:string, mapId?:string, teamId?:string, keyId?:string, privateKey?:string}>
     */
    public array $keys = [];
    public string $googleRefresh = 'refresh';

    // Locator
    public string $look = 'side';
    public string $accentColor = '#17694f';
    public string $markerColor = '#17694f';
    public string $corners = 'rounded';
    public string $font = 'geist';
    public string $unit = 'km';
    public int $defaultRadius = 25;
    public int $resultsPerPage = 20;

    // Privacy
    public bool $consentRequired = false;

    // Visitor location (F11)
    public string $geoLiteDb = '';
    public array $redirects = [];

    // Insights and templates
    public bool $collectInsights = true;
    public array $extraTemplateRoots = [];
    public bool $geocodeAddresses = false;

    /** A key with environment variables resolved. */
    public function key(string $provider, string $kind = 'server'): string
    {
        return (string)App::parseEnv($this->keys[$provider][$kind] ?? '');
    }

    /** Google results may only show on Google maps, so front-end maps follow the geocoder (F8). */
    public function frontEndProvider(): string
    {
        return $this->geocoder === 'google' ? 'google' : $this->mapProvider;
    }

    public function pairingWarning(): bool
    {
        return $this->geocoder === 'google' && $this->mapProvider !== 'google';
    }

    protected function defineRules(): array
    {
        return [
            [['mapProvider'], 'in', 'range' => self::PROVIDERS],
            [['geocoder'], 'in', 'range' => self::GEOCODERS],
            [['mapStyle'], 'in', 'range' => self::STYLES],
            [['look'], 'in', 'range' => self::LOOKS],
            [['unit'], 'in', 'range' => ['km', 'mi']],
            [['corners'], 'in', 'range' => ['rounded', 'square']],
            [['googleRefresh'], 'in', 'range' => ['refresh', 'clear']],
            [['accentColor', 'markerColor'], 'match', 'pattern' => '/^#[0-9a-fA-F]{6}$/'],
            [['defaultRadius'], 'integer', 'min' => 1, 'max' => 500],
            [['resultsPerPage'], 'integer', 'min' => 1, 'max' => 100],
            [['nominatimUrl', 'photonUrl'], 'validateServerUrl'],
        ];
    }

    /** Self-hosted Nominatim/Photon: an https URL, or an environment variable that resolves to one. */
    public function validateServerUrl(string $attribute): void
    {
        $raw = trim((string)$this->$attribute);
        if ($raw === '') {
            return;
        }
        $url = (string)App::parseEnv($raw);
        if ($url === $raw && str_starts_with($raw, '$')) {
            return; // env var not set on this machine (yet); checked where it is used
        }
        if (!preg_match('~^https?://[^\s/$.?#][^\s]*$~i', $url)) {
            $this->addError($attribute, \Craft::t('rumbo-maps', 'Enter a full address starting with https://, or an environment variable.'));
        }
    }
}
