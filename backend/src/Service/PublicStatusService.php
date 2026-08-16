<?php

declare(strict_types=1);

namespace ColoManager\Service;

use ColoManager\Repository\IncidentRepository;
use ColoManager\Repository\MaintenanceRepository;
use ColoManager\Support\DocumentSerializer;
use DateTimeImmutable;

/** Liefert ausschließlich ausdrücklich veröffentlichte Informationen für die Statusseite. */
final readonly class PublicStatusService
{
    public function __construct(
        private IncidentRepository $incidents,
        private MaintenanceRepository $maintenance,
    ) {
    }

    /** @return array<string, mixed> */
    public function getSystemStatus(): array
    {
        $activeIncidents = $this->incidents->findActivePublic();
        $criticalIncidents = array_filter($activeIncidents, static fn (array $item): bool =>
            ($item['critical'] ?? false) === true || ($item['priority'] ?? null) === 'critical'
        );
        $activeMaintenance = $this->maintenance->findActivePublic();

        $status = match (true) {
            $criticalIncidents !== [] => 'critical',
            $activeIncidents !== [] => 'degraded',
            $activeMaintenance !== [] => 'maintenance',
            default => 'operational',
        };

        return [
            'status' => $status,
            'activeIncidents' => count($activeIncidents),
            'criticalIncidents' => count($criticalIncidents),
            'activeMaintenance' => count($activeMaintenance),
            'plannedMaintenance' => $this->maintenance->countPlannedPublic(),
            'lastUpdated' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function getCombinedStatus(): array
    {
        return [
            'system' => $this->getSystemStatus(),
            'incidents' => $this->getPublicIncidents(1, 25),
            'maintenance' => $this->getPublicMaintenance(1, 25),
            'history' => $this->getPublicHistory(10),
        ];
    }

    /** @return array<string, mixed> */
    public function getPublicIncidents(int $page, int $limit): array
    {
        $result = $this->incidents->listActivePublic($page, $limit);
        $result['items'] = array_map($this->publicIncident(...), $result['items']);
        return DocumentSerializer::serialize($result);
    }

    /** @return array<string, mixed> */
    public function getPublicMaintenance(int $page, int $limit): array
    {
        $result = $this->maintenance->listUpcomingPublic($page, $limit);
        $result['items'] = array_map($this->publicMaintenance(...), $result['items']);
        return DocumentSerializer::serialize($result);
    }

    /** @return list<array<string, mixed>> */
    public function getPublicHistory(int $limit): array
    {
        $incidents = array_map(function (array $item): array {
            $public = $this->publicIncident($item);
            $public['type'] = 'incident';
            $public['resolvedAt'] = $item['endAt'] ?? $item['updatedAt'] ?? null;
            return $public;
        }, $this->incidents->findRecentResolvedPublic($limit));

        $maintenance = array_map(function (array $item): array {
            $public = $this->publicMaintenance($item);
            $public['type'] = 'maintenance';
            $public['resolvedAt'] = $item['plannedEnd'] ?? $item['updatedAt'] ?? null;
            return $public;
        }, $this->maintenance->findRecentCompletedPublic($limit));

        $history = array_merge($incidents, $maintenance);
        usort($history, static function (array $left, array $right): int {
            $leftDate = DocumentSerializer::serialize($left['resolvedAt'] ?? null) ?? '';
            $rightDate = DocumentSerializer::serialize($right['resolvedAt'] ?? null) ?? '';
            return strcmp((string) $rightDate, (string) $leftDate);
        });

        return DocumentSerializer::serialize(array_slice($history, 0, $limit));
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function publicIncident(array $item): array
    {
        return [
            'id' => $item['_id'] ?? null,
            'title' => $item['title'] ?? '',
            'description' => $item['description'] ?? '',
            'status' => $item['status'] ?? '',
            'priority' => $item['priority'] ?? '',
            'critical' => $item['critical'] ?? false,
            'startAt' => $item['startAt'] ?? null,
            'endAt' => $item['endAt'] ?? null,
            'updatedAt' => $item['updatedAt'] ?? null,
        ];
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function publicMaintenance(array $item): array
    {
        return [
            'id' => $item['_id'] ?? null,
            'title' => $item['title'] ?? '',
            'description' => $item['description'] ?? '',
            'status' => $item['status'] ?? '',
            'impact' => $item['impact'] ?? '',
            'plannedStart' => $item['plannedStart'] ?? null,
            'plannedEnd' => $item['plannedEnd'] ?? null,
            'updatedAt' => $item['updatedAt'] ?? null,
        ];
    }
}
