<?php
class ClockController {

    /** GET — Today's Schedule view (no auth required — visible from staff picker) */
    public function schedule(): void {

        $model    = new ScheduleAttendance();
        $schedule = $model->isAvailable() ? $model->getTodaySchedule() : [];

        $pageTitle = "Today's Schedule";
        ob_start();
        require APP_PATH . '/views/clock/schedule.php';
        $content = ob_get_clean();
        require APP_PATH . '/views/layouts/admin.php';
    }

    public function clockOut(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('/');
            return;
        }
        verifyCsrfToken();

        $attendanceId = $_SESSION['pos_attendance_id'] ?? null;
        $schedUserId  = $_SESSION['pos_sched_user_id'] ?? null;

        if ($attendanceId && $schedUserId) {
            $model = new ScheduleAttendance();
            if ($model->isAvailable()) {
                $model->clockOut($attendanceId, $schedUserId);
                $time = date('g:i A');
                setFlash('success', "Clocked out at $time");
            }
        }

        clearOperator();
        redirect('/');
    }
}
