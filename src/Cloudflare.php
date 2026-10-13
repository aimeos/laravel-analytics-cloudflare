<?php

namespace Aimeos\AnalyticsBridge\Drivers;

use Aimeos\AnalyticsBridge\Contracts\Driver;
use Illuminate\Support\Facades\Http;

class Cloudflare implements Driver
{
    private array $types = ['views', 'visits', 'durations', 'conversions', 'countries', 'referrers', 'links'];
    private ?string $siteTag;
    private ?string $token;


    public function __construct(array $config = [])
    {
        $this->siteTag = $config['siteTag'] ?? null;
        $this->token   = $config['token'] ?? null;
    }


    public function types(array $types): Driver
    {
        $this->types = $types;
        return $this;
    }


    public function stats(string $url, int $days = 30): ?array
    {
        if (!$this->siteTag || !$this->token) {
            return null;
        }

        $startDate = now()->subDays($days)->toDateString();
        $endDate   = now()->toDateString();

        $page = $this->pageFilter($url);

        $fragments = [];
        if (in_array('views', $this->types)) {
            $fragments[] = $this->queryTraffic($page, $startDate, $endDate);
        }
        if (in_array('countries', $this->types)) {
            $fragments[] = $this->queryCountries($page, $startDate, $endDate);
        }
        if (in_array('referrers', $this->types)) {
            $fragments[] = $this->queryReferrers($page, $startDate, $endDate);
        }
        if (in_array('links', $this->types)) {
            $fragments[] = $this->queryLinks($url, $startDate, $endDate);
        }

        if (empty($fragments)) {
            return $this->emptyResult();
        }

        $query = "query {
          viewer {
            zones(filter: {zoneTag: \"{$this->siteTag}\"}) {
              " . implode("\n", $fragments) . "
            }
          }
        }";

        $response = Http::withToken($this->token)
            ->post('https://api.cloudflare.com/client/v4/graphql', [
                'query' => $query,
            ]);

        if (!$response->ok() || $response->json('errors')) {
            throw new \RuntimeException($response->body());
        }

        $zone = $response->json('data.viewer.zones.0') ?? [];
        $result = [];

        if (in_array('views', $this->types) && isset($zone['traffic'])) {
            $result['views'] = $this->mapDaily($zone['traffic']);
        }

        if (in_array('visits', $this->types)) {
            $result['visits'] = [];
        }

        if (in_array('conversions', $this->types)) {
            $result['conversions'] = [];
        }

        if (in_array('durations', $this->types)) {
            $result['durations'] = [];
        }

        if (in_array('countries', $this->types) && isset($zone['countries'])) {
            $result['countries'] = $this->mapCountries($zone['countries']);
        }

        if (in_array('referrers', $this->types) && isset($zone['referrers'])) {
            $result['referrers'] = $this->mapReferrers($zone['referrers']);
        }

        if (in_array('links', $this->types) && isset($zone['links'])) {
            $result['links'] = $this->mapLinks($zone['links'], $url);
        }

        return $result;
    }


    protected function queryTraffic(string $page, string $start, string $end): string
    {
        return <<<GQL
      traffic: httpRequestsAdaptiveGroups(
        filter: {
          datetime_geq: "{$start}T00:00:00Z",
          datetime_leq: "{$end}T23:59:59Z",
          {$page}
        },
        orderBy: [datetime_ASC]
      ) {
        dimensions {
          date: datetime
        }
        count
      }
GQL;
    }


    protected function queryCountries(string $page, string $start, string $end): string
    {
        return <<<GQL
      countries: httpRequestsAdaptiveGroups(
        limit: 99,
        filter: {
          datetime_geq: "{$start}T00:00:00Z",
          datetime_leq: "{$end}T23:59:59Z",
          {$page}
        },
        orderBy: [count_DESC]
      ) {
        dimensions {
          country: clientCountryName
        }
        count
      }
GQL;
    }


    protected function queryReferrers(string $page, string $start, string $end): string
    {
        return <<<GQL
      referrers: httpRequestsAdaptiveGroups(
        filter: {
          datetime_geq: "{$start}T00:00:00Z",
          datetime_leq: "{$end}T23:59:59Z",
          {$page}
        },
        orderBy: [count_DESC]
      ) {
        dimensions {
          referer: clientRefererHost
        }
        count
      }
GQL;
    }


    protected function queryLinks(string $url, string $start, string $end): string
    {
        $host = json_encode(parse_url($url, PHP_URL_HOST) ?? '', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $path = json_encode(parse_url($url, PHP_URL_PATH) ?? '/', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // HTML pages requested with the page as referrer
        return <<<GQL
      links: httpRequestsAdaptiveGroups(
        limit: 100,
        filter: {
          datetime_geq: "{$start}T00:00:00Z",
          datetime_leq: "{$end}T23:59:59Z",
          clientRefererHost: {$host},
          clientRefererPath: {$path},
          edgeResponseContentTypeName: "html",
          requestSource: "eyeball"
        },
        orderBy: [count_DESC]
      ) {
        dimensions {
          host: clientRequestHTTPHost
          path: clientRequestPath
        }
        count
      }
GQL;
    }


    protected function pageFilter(string $url): string
    {
        $host = json_encode(parse_url($url, PHP_URL_HOST) ?? '', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $path = json_encode(parse_url($url, PHP_URL_PATH) ?? '/', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return "clientRequestHTTPHost: {$host}, clientRequestPath: {$path}";
    }


    protected function mapDaily(array $groups): array
    {
        return collect($groups)
            ->map(fn($row) => [
                'key' => substr($row['dimensions']['date'], 0, 10),
                'value' => $row['count'] ?? 0,
            ])
            ->groupBy('key')
            ->map(fn($items, $date) => [
                'key' => $date,
                'value' => collect($items)->sum('value'),
            ])
            ->values()
            ->all();
    }


    protected function mapCountries(array $groups): array
    {
        return collect($groups)
            ->map(fn($row) => [
                'key'   => $row['dimensions']['country'] ?? 'Unknown',
                'value' => $row['count'] ?? 0,
            ])
            ->values()
            ->all();
    }


    protected function mapLinks(array $groups, string $url): array
    {
        $self = parse_url($url, PHP_URL_HOST) . parse_url($url, PHP_URL_PATH);

        return collect($groups)
            ->map(fn($row) => [
                'key'   => ($row['dimensions']['host'] ?? '') . ($row['dimensions']['path'] ?? ''),
                'value' => $row['count'] ?? 0,
            ])
            ->filter(fn($item) => $item['key'] !== $self) // reloads
            ->values()
            ->all();
    }


    protected function mapReferrers(array $groups): array
    {
        $categorized = collect($groups)
            ->map(function ($row) {
                $host = strtolower($row['dimensions']['referer'] ?? '');
                $type = $this->classifyReferrer($host);
                $requests = $row['count'] ?? 0;

                return [
                    'type' => $type,
                    'host' => $row['dimensions']['referer'] ?? '',
                    'value' => $requests,
                ];
            })
            ->groupBy('type')
            ->map(fn($items, $type) => [
                'key'   => $type,
                'value' => collect($items)->sum('value'),
                'rows'  => $type === 'Direct entry' ? [] : collect($items)
                    ->map(fn($item) => [
                        'key'   => $item['host'],
                        'value' => $item['value'],
                    ])
                    ->sortByDesc('value')
                    ->values()
                    ->all(),
            ])
            ->sortByDesc('value')
            ->values()
            ->all();

        return $categorized;
    }


    protected function classifyReferrer(string $host): string
    {
        if (!$host || $host === '-') {
            return 'Direct entry';
        }

        $searchEngines = ['google.', 'bing.', 'yahoo.', 'duckduckgo.', 'baidu.', 'yandex.'];
        if (collect($searchEngines)->contains(fn($s) => str_contains($host, $s))) {
            return 'Search Engines';
        }

        $socialSites = ['facebook.', 'twitter.', 'linkedin.', 'instagram.', 't.co', 'pinterest.', 'reddit.'];
        if (collect($socialSites)->contains(fn($s) => str_contains($host, $s))) {
            return 'Social Networks';
        }

        $aiProviders = ['chatgpt.', 'openai.', 'perplexity.', 'claude.', 'anthropic.', 'gemini.', 'bard.', 'copilot.'];
        if (collect($aiProviders)->contains(fn($s) => str_contains($host, $s))) {
            return 'AI Assistants';
        }

        return 'Website';
    }


    protected function emptyResult(): array
    {
        $result = [];
        foreach (['views', 'visits', 'conversions', 'durations', 'countries', 'referrers', 'links'] as $type) {
            if (in_array($type, $this->types)) {
                $result[$type] = [];
            }
        }
        return $result;
    }
}
