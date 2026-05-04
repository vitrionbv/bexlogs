<?php

namespace App\Services;

/**
 * Reads host-level resource usage from inside the container.
 *
 * On Docker (Linux) without explicit cgroup limits, /proc inside a container
 * still exposes the host's CPU + memory counters — which is exactly what we
 * want to surface on the admin dashboard. For disk we read the overlayfs
 * stats at "/", which always sit on top of the host's root filesystem and
 * therefore report the underlying device total/free.
 *
 * If /proc isn't readable (e.g. running tests on macOS) every accessor
 * returns nulls so the consumer can degrade gracefully.
 */
class ServerMetrics
{
    /**
     * Sample CPU twice with a short gap and compute the percentage of
     * non-idle time across all cores. /proc/stat columns:
     *   user nice system idle iowait irq softirq steal guest guest_nice
     *
     * @return float|null 0.0–100.0 or null if /proc/stat is unreadable
     */
    public function cpuPercent(int $sampleGapMicroseconds = 200_000): ?float
    {
        $a = $this->readCpuLine();
        if ($a === null) {
            return null;
        }
        usleep($sampleGapMicroseconds);
        $b = $this->readCpuLine();
        if ($b === null) {
            return null;
        }

        $idleA = ($a[3] ?? 0) + ($a[4] ?? 0);
        $idleB = ($b[3] ?? 0) + ($b[4] ?? 0);
        $totalA = array_sum($a);
        $totalB = array_sum($b);

        $totalDelta = $totalB - $totalA;
        $idleDelta = $idleB - $idleA;

        if ($totalDelta <= 0) {
            return 0.0;
        }

        $busy = max(0.0, ($totalDelta - $idleDelta) / $totalDelta);

        return round($busy * 100, 1);
    }

    /**
     * @return array{total:int,used:int,available:int,percent:float}|null
     */
    public function memory(): ?array
    {
        $info = $this->readMeminfo();
        if ($info === null) {
            return null;
        }

        // /proc/meminfo numbers are kB; multiply to bytes for consistency
        // with the disk readout below.
        $total = (int) (($info['MemTotal'] ?? 0) * 1024);
        $available = (int) (($info['MemAvailable'] ?? 0) * 1024);
        if ($total <= 0) {
            return null;
        }
        $used = max(0, $total - $available);

        return [
            'total' => $total,
            'used' => $used,
            'available' => $available,
            'percent' => round(($used / $total) * 100, 1),
        ];
    }

    /**
     * @return array{total:int,used:int,free:int,percent:float}|null
     */
    public function disk(string $path = '/'): ?array
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if ($total === false || $free === false || $total <= 0) {
            return null;
        }

        $total = (int) $total;
        $free = (int) $free;
        $used = max(0, $total - $free);

        return [
            'total' => $total,
            'used' => $used,
            'free' => $free,
            'percent' => round(($used / $total) * 100, 1),
        ];
    }

    /**
     * @return array{1:float,5:float,15:float}|null
     */
    public function loadAverage(): ?array
    {
        $raw = @file_get_contents('/proc/loadavg');
        if ($raw === false) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($raw));
        if (count($parts) < 3) {
            return null;
        }

        return [
            1 => (float) $parts[0],
            5 => (float) $parts[1],
            15 => (float) $parts[2],
        ];
    }

    public function uptimeSeconds(): ?int
    {
        $raw = @file_get_contents('/proc/uptime');
        if ($raw === false) {
            return null;
        }

        $first = strtok(trim($raw), ' ');

        return $first === false ? null : (int) (float) $first;
    }

    public function cpuCount(): int
    {
        $raw = @file_get_contents('/proc/cpuinfo');
        if ($raw === false) {
            return 1;
        }

        return max(1, substr_count($raw, "\nprocessor\t") + (str_starts_with($raw, 'processor') ? 1 : 0));
    }

    /**
     * Single combined snapshot used by the dashboard event payload.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'hostname' => gethostname() ?: null,
            'cpu' => [
                'percent' => $this->cpuPercent(),
                'cores' => $this->cpuCount(),
            ],
            'memory' => $this->memory(),
            'disk' => $this->disk('/'),
            'load' => $this->loadAverage(),
            'uptime_seconds' => $this->uptimeSeconds(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Read the aggregate "cpu" line from /proc/stat as an array of integers.
     *
     * @return array<int,int>|null
     */
    private function readCpuLine(): ?array
    {
        $raw = @file_get_contents('/proc/stat');
        if ($raw === false) {
            return null;
        }
        if (! preg_match('/^cpu\s+(.*)$/m', $raw, $m)) {
            return null;
        }

        return array_map('intval', preg_split('/\s+/', trim($m[1])));
    }

    /**
     * @return array<string,int>|null
     */
    private function readMeminfo(): ?array
    {
        $raw = @file_get_contents('/proc/meminfo');
        if ($raw === false) {
            return null;
        }

        $out = [];
        foreach (explode("\n", $raw) as $line) {
            if (! preg_match('/^([A-Za-z()_]+):\s+(\d+)/', $line, $m)) {
                continue;
            }
            $out[$m[1]] = (int) $m[2];
        }

        return $out;
    }
}
