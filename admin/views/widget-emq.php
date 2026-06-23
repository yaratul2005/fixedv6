<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$history = get_option('servertrack_emq_history', []);
$avg_emq = 0;
if ( count($history) > 0 ) {
    $avg_emq = array_sum($history) / count($history);
}

$grade = 'Poor';
$color = '#EF4444';
$desc = 'Increase user identifiers (Email, Phone) immediately.';

if ($avg_emq >= 0.9) {
    $grade = 'Excellent';
    $color = '#10B981';
    $desc = 'Highest match accuracy achieved.';
} elseif ($avg_emq >= 0.7) {
    $grade = 'Good';
    $color = '#34A853';
    $desc = 'Good matching, well configured.';
} elseif ($avg_emq >= 0.5) {
    $grade = 'Fair';
    $color = '#F59E0B';
    $desc = 'Acceptable but consider passing more user context.';
}
?>
<div class="st-kpi-card" style="border-top-color: <?php echo esc_attr($color); ?>">
    <div class="st-kpi-title">Event Match Quality (EMQ)</div>
    <div class="st-kpi-value" style="color: <?php echo esc_attr($color); ?>">
        <?php echo number_format($avg_emq * 10, 1); ?> <span style="font-size:16px;">/ 10</span>
    </div>
    <div class="st-kpi-sub">
        <strong style="color: <?php echo esc_attr($color); ?>"><?php echo esc_html($grade); ?></strong> &middot;
        <?php echo esc_html($desc); ?>
    </div>
</div>
