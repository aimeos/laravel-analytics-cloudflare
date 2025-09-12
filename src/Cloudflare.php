<?php

namespace Aimeos\AnalyticsBridge\Drivers;

use Aimeos\AnalyticsBridge\Contracts\Driver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class Cloudflare implements Driver
{
    private array $types = ['views', 'visits', 'durations', 'conversions', 'countries', 'referrers'];
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

        $queries = [];
        if (array_intersect($this->types, ['views', 'visits'])) {
            $queries['traffic'] = $this->queryTraffic($url, $startDate, $endDate);
        }
        if (in_array('countries', $this->types)) {
            $queries['countries'] = $this->queryCountries($url, $startDate, $endDate);
        }
        if (in_array('referrers', $this->types)) {
            $queries['referrers'] = $this->queryReferrers($url, $startDate, $endDate);
        }

        $response = Http::withToken($this->token)
            ->post('https://api.cloudflare.com/client/v4/graphql', [
                'query' => "query { " . implode("\n", $queries) . " }"
            ]);

        if (!$response->ok()) {
            throw new \RuntimeException($response->body());
        }

        $data = $response->json('data.viewer.zones.0') ?? [];
        $result = [];

        $totalRequests = 0;

        if (isset($data['traffic']['httpRequestsAdaptiveGroups'])) {
            $traffic = $data['traffic']['httpRequestsAdaptiveGroups'];
            $totalRequests = collect($traffic)->sum(fn($row) => $row['sum']['requests'] ?? 0);

            if (in_array('views', $this->types)) {
                $result['views'] = $this->mapDaily($traffic, 'requests');
            }
            if (in_array('visits', $this->types)) {
                $result['visits'] = $this->mapDaily($traffic, 'uniques');
            }
        }

        if (isset($data['countries']['httpRequestsAdaptiveGroups'])) {
            $countries = $data['countries']['httpRequestsAdaptiveGroups'];
            $result['countries'] = $this->mapCountriesWithOther($countries, $totalRequests);
        }

        if (isset($data['referrers']['httpRequestsAdaptiveGroups'])) {
            $referrers = $data['referrers']['httpRequestsAdaptiveGroups'];
            $result['referrers'] = $this->mapReferrerTypesWithOther($referrers, $totalRequests);
        }

        if (in_array('durations', $this->types)) {
            $result['durations'] = [];
        }
        if (in_array('conversions', $this->types)) {
            $result['conversions'] = [];
        }

        return $result;
    }

    /*----------------------------------------
     | GraphQL Sub-Queries
     *---------------------------------------*/
    protected function queryTraffic(string $url, string $start, string $end): string
    {
        return <<<GQL
traffic: viewer {
  zones(filter: {zoneTag: "{$this->siteTag}"}) {
    httpRequestsAdaptiveGroups(
      filter: {
        datetime_geq: "{$start}T00:00:00Z",
        datetime_leq: "{$end}T23:59:59Z",
        requestPath: "{$url}"
      },
      orderBy: [datetime_ASC]
    ) {
      dimensions {
        date: datetime
      }
      sum {
        requests
        uniques
      }
    }
  }
}
GQL;
    }

    protected function queryCountries(string $url, string $start, string $end): string
    {
        return <<<GQL
countries: viewer {
  zones(filter: {zoneTag: "{$this->siteTag}"}) {
    httpRequestsAdaptiveGroups(
      limit: 99,
      filter: {
        datetime_geq: "{$start}T00:00:00Z",
        datetime_leq: "{$end}T23:59:59Z",
        requestPath: "{$url}"
      },
      orderBy: [sum_requests_DESC]
    ) {
      dimensions {
        country: clientCountryName
      }
      sum {
        requests
      }
    }
  }
}
GQL;
    }

    protected function queryReferrers(string $url, string $start, string $end): string
    {
        return <<<GQL
referrers: viewer {
  zones(filter: {zoneTag: "{$this->siteTag}"}) {
    httpRequestsAdaptiveGroups(
      filter: {
        datetime_geq: "{$start}T00:00:00Z",
        datetime_leq: "{$end}T23:59:59Z",
        requestPath: "{$url}"
      },
      orderBy: [sum_requests_DESC]
    ) {
      dimensions {
        referer: clientRefererHost
      }
      sum {
        requests
      }
    }
  }
}
GQL;
    }

    /*----------------------------------------
     | Mapping Helpers
     *---------------------------------------*/
    protected function mapDaily(array $response, string $field): array
    {
        return collect($response)
            ->map(fn($row) => [
                'key' => substr($row['dimensions']['date'], 0, 10),
                'value' => $row['sum'][$field] ?? 0,
            ])
            ->groupBy('key')
            ->map(fn($items, $date) => [
                'key' => $date,
                'value' => collect($items)->sum('value'),
            ])
            ->values()
            ->all();
    }

    protected function mapCountriesWithOther(array $response, int $total): array
    {
        $mapped = collect($response)
            ->map(fn($row) => [
                'key'   => $row['dimensions']['country'] ?? 'Unknown',
                'value' => $row['sum']['requests'] ?? 0,
            ])
            ->values();

        $sum = $mapped->sum('value');
        $diff = $total - $sum;

        if ($diff > 0) {
            $mapped->push([
                'key'   => 'Other',
                'value' => $diff,
            ]);
        }

        return $mapped->all();
    }

    protected function mapReferrerTypesWithOther(array $response, int $total): array
    {
        $searchEngines = ['google.', 'bing.', 'yahoo.', 'duckduckgo.', 'baidu.', 'yandex.'];
        $socialSites   = ['facebook.', 'twitter.', 'linkedin.', 'instagram.', 't.co', 'pinterest.', 'reddit.'];

        $mapped = collect($response)
            ->map(function($row) use ($searchEngines, $socialSites) {
                $host = strtolower($row['dimensions']['referer'] ?? '');
                $type = $this->classifyReferrer($host);

                return [
                    'key'   => $type,
                    'value' => $row['sum']['requests'] ?? 0,
                ];
            })
            ->groupBy('key')
            ->map(fn($items, $key) => [
                'key'   => $key,
                'value' => collect($items)->sum('value'),
            ])
            ->values();

        $sum = $mapped->sum('value');
        $diff = $total - $sum;

        if ($diff > 0) {
            $mapped->push([
                'key'   => 'Other',
                'value' => $diff,
            ]);
        }

        return $mapped->all();
    }


    protected function classifyReferrer(string $host): string
    {
        if (!$host || $host === '-') {
            return 'Direct';
        }

        // Search engines
        $searchEngines = ['google.', 'bing.', 'yahoo.', 'duckduckgo.', 'baidu.', 'yandex.'];
        if (collect($searchEngines)->contains(fn($s) => str_contains($host, $s))) {
            return 'Search Engines';
        }

        // Social media
        $socialSites = ['facebook.', 'twitter.', 'linkedin.', 'instagram.', 't.co', 'pinterest.', 'reddit.'];
        if (collect($socialSites)->contains(fn($s) => str_contains($host, $s))) {
            return 'Social Networks';
        }

        // AI assistants
        $aiProviders = ['chatgpt.', 'openai.', 'perplexity.', 'claude.', 'anthropic.', 'gemini.', 'bard.', 'copilot.'];
        if (collect($aiProviders)->contains(fn($s) => str_contains($host, $s))) {
            return 'AI Assistants';
        }

        return 'Websites';
    }
}
