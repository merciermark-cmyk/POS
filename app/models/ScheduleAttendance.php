<?php
/**
 * Cross-DB model for schedule attendance (clock in/out).
 * Uses getSchedDB() directly — does NOT extend BaseModel.
 */
class ScheduleAttendance {

    private ?PDO $db;

    public function __construct() {
        $this->db = getSchedDB();
    }

    public function isAvailable(): bool {
        return $this->db !== null;
    }

    /**
     * Get today's shifts (with attendance) for a schedule user.
     */
    public function getShiftsToday(int $schedUserId): array {
        if (!$this->db) return [];
        $stmt = $this->db->prepare(
            "SELECT s.id AS shift_id, s.scheduled_start, s.scheduled_end,
                    a.id AS attendance_id, a.clock_in, a.clock_out
             FROM shifts s
             LEFT JOIN attendance a ON a.shift_id = s.id
             WHERE s.user_id = ? AND s.shift_date = CURDATE()
             ORDER BY s.scheduled_start"
        );
        $stmt->execute([$schedUserId]);
        return $stmt->fetchAll();
    }

    /**
     * Pick the shift closest to the current time (best fit for clock-in).
     */
    public function pickBestShift(array $shifts): ?array {
        if (empty($shifts)) return null;

        $now = time();
        $best = null;
        $bestDiff = PHP_INT_MAX;

        foreach ($shifts as $s) {
            // Skip shifts already clocked out
            if ($s['clock_out']) continue;

            $start = strtotime('today ' . $s['scheduled_start']);
            $diff = abs($now - $start);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $s;
            }
        }

        return $best;
    }

    /**
     * Clock in: INSERT attendance record. Catches UNIQUE violation (already clocked in).
     * Returns attendance ID or null if already exists.
     */
    public function clockIn(int $shiftId, int $schedUserId): ?int {
        if (!$this->db) return null;
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO attendance (shift_id, clock_in, recorded_by)
                 VALUES (?, NOW(), ?)"
            );
            $stmt->execute([$shiftId, $schedUserId]);
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            // UNIQUE violation = already clocked in for this shift
            if ($e->getCode() == 23000) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Clock out: UPDATE clock_out = NOW().
     */
    public function clockOut(int $attendanceId, int $schedUserId): void {
        if (!$this->db) return;
        $stmt = $this->db->prepare(
            "UPDATE attendance SET clock_out = NOW(), recorded_by = ? WHERE id = ? AND clock_out IS NULL"
        );
        $stmt->execute([$schedUserId, $attendanceId]);
    }

    /**
     * Find open attendance (clocked in, not clocked out) for a schedule user today.
     * Used for session recovery after idle timeout.
     */
    public function getOpenAttendance(int $schedUserId): ?array {
        if (!$this->db) return null;
        $stmt = $this->db->prepare(
            "SELECT a.id AS attendance_id, a.shift_id, a.clock_in,
                    s.scheduled_start, s.scheduled_end
             FROM attendance a
             JOIN shifts s ON s.id = a.shift_id
             WHERE s.user_id = ? AND s.shift_date = CURDATE()
               AND a.clock_in IS NOT NULL AND a.clock_out IS NULL
             ORDER BY a.clock_in DESC
             LIMIT 1"
        );
        $stmt->execute([$schedUserId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Get today's full schedule: all shifts with attendance + user names.
     */
    public function getTodaySchedule(): array {
        if (!$this->db) return [];
        $stmt = $this->db->query(
            "SELECT s.id AS shift_id, s.scheduled_start, s.scheduled_end,
                    s.location, s.notes AS shift_notes,
                    u.name AS staff_name,
                    a.id AS attendance_id, a.clock_in, a.clock_out, a.notes AS att_notes
             FROM shifts s
             JOIN users u ON u.id = s.user_id
             LEFT JOIN attendance a ON a.shift_id = s.id
             WHERE s.shift_date = CURDATE()
             ORDER BY s.scheduled_start, u.name"
        );
        return $stmt->fetchAll();
    }

    /**
     * Get all schedule users (for admin dropdown).
     */
    public function getScheduleUsers(): array {
        if (!$this->db) return [];
        $stmt = $this->db->query(
            "SELECT id, name FROM users WHERE is_active = 1 ORDER BY name"
        );
        return $stmt->fetchAll();
    }
}
