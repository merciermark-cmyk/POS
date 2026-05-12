<h2 class="mb-3"><i class="bi bi-calendar-check"></i> Today's Schedule
    <small class="text-muted fs-6"><?= date('l, F j, Y') ?></small>
</h2>

<a href="<?= baseUrl('sale') ?>" class="btn btn-outline-secondary mb-3">
    <i class="bi bi-arrow-left"></i> Back to Terminal
</a>

<?php if (empty($schedule)): ?>
    <div class="alert alert-info">No shifts scheduled for today, or schedule database is not available.</div>
<?php else: ?>
<div class="table-responsive">
    <table class="table table-bordered table-hover align-middle">
        <thead class="table-dark">
            <tr>
                <th>Name</th>
                <th>Shift</th>
                <th>Location</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($schedule as $row):
                $start = date('g:i A', strtotime($row['scheduled_start']));
                $end   = date('g:i A', strtotime($row['scheduled_end']));
                $loc   = ucfirst($row['location'] ?? 'shop');

                // Determine status
                if ($row['clock_in'] && $row['clock_out']) {
                    $statusClass = 'text-bg-success';
                    $statusText  = 'Done ' . date('g:i', strtotime($row['clock_in'])) . '–' . date('g:i A', strtotime($row['clock_out']));
                } elseif ($row['clock_in']) {
                    $statusClass = 'text-bg-primary';
                    $statusText  = 'Clocked in ' . date('g:i A', strtotime($row['clock_in']));
                } else {
                    // Check if shift hasn't started yet vs late
                    $now = time();
                    $shiftStart = strtotime('today ' . $row['scheduled_start']);
                    if ($now < $shiftStart) {
                        $statusClass = 'text-bg-secondary';
                        $statusText  = 'Upcoming';
                    } else {
                        $statusClass = 'text-bg-warning text-dark';
                        $statusText  = 'Not clocked in';
                    }
                }
            ?>
            <tr>
                <td class="fw-bold"><?= e($row['staff_name']) ?></td>
                <td><?= $start ?> – <?= $end ?></td>
                <td>
                    <?php if ($loc === 'Office'): ?>
                        <span class="badge bg-secondary">Office</span>
                    <?php else: ?>
                        <span class="badge bg-info text-dark">Shop</span>
                    <?php endif; ?>
                </td>
                <td><span class="badge <?= $statusClass ?>"><?= $statusText ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
