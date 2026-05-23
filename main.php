<?php
/**
 * Plugin Name: WooCommerce Sales Polynomial Regression Forecast
 * Description: วิเคราะห์แนวโน้มและทำนายยอดขายรายเดือนล่วงหน้า 3 เดือนด้วย Polynomial Regression
 * Version: 1.0.0
 * Author: Jirakit Pawnsakunrungrot
 * Author URI: https://www.linkedin.com/in/sunny-jirakit
 * Plugin URI: https://github.com/sunny420x/woocommerce-regression
 * License: GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // ดักความปลอดภัย
}

// 1. สร้างเมนูในหน้าหลังบ้านของ WordPress
add_action( 'admin_menu', 'wclrf_register_admin_menu' );
function wclrf_register_admin_menu() {
    $page_hook = add_submenu_page(
        'woocommerce',
        'ทำนายยอดขาย Polynomial regression',
        'ทำนายยอดขาย Polynomial regression',
        'manage_options',
        'wc-sales-forecast-lr',
        'wclrf_render_dashboard_page'
    );

    add_action( 'admin_print_scripts-' . $page_hook, 'wclrf_enqueue_chart_js' );
}

function wclrf_enqueue_chart_js() {
    wp_enqueue_script(
        'chart-js-cdn', 
        'https://cdn.jsdelivr.net/npm/chart.js', 
        array(), 
        '4.4.1', 
        false // โหลดไว้ที่ส่วนหัว (Header) เพื่อให้ตัวแปร Chart พร้อมใช้งานร้อยเปอร์เซ็นต์
    );
}

// 2. ฟังก์ชันหลักในการดึงข้อมูลและคำนวณ Regression
function wclrf_calculate_regression_data() {
    global $wpdb;

    // ดึงข้อมูลออเดอร์ย้อนหลัง 12 เดือน
    $query = "
        SELECT 
            DATE_FORMAT(p.post_date, '%Y-%m') as sales_month,
            SUM(meta.meta_value) as total_sales
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} meta ON p.ID = meta.post_id
        WHERE p.post_type = 'shop_order'
          AND p.post_status IN ('wc-completed', 'wc-processing')
          AND meta.meta_key = '_order_total'
          AND p.post_date >= DATE_SUB(DATE_FORMAT(NOW() ,'%Y-%m-01'), INTERVAL 12 MONTH)
          AND p.post_date < DATE_FORMAT(NOW() ,'%Y-%m-01')
        GROUP BY sales_month
        ORDER BY sales_month ASC
    ";

    $results = $wpdb->get_results( $query, ARRAY_A );

    if ( empty( $results ) ) {
        return false;
    }

    $x = array();
    $y = array();
    $months_label = array();
    
    $i = 1;
    foreach ( $results as $row ) {
        $x[] = $i;
        $y[] = (float) $row['total_sales'];
        $months_label[$i] = $row['sales_month'];
        $i++;
    }

    $n = count( $x );
    if ( $n < 3 ) {
        return array( 'error' => 'ข้อมูลต้องมีอย่างน้อย 3 เดือนขึ้นไป ถึงจะคำนวณทางโค้ง Polynomial (Degree 2) ได้' );
    }

    // เตรียมค่าเพื่อแก้สมการ Matrix (คำนวณหาค่าก้อนสถิติ)
    $sum_x   = array_sum($x);
    $sum_y   = array_sum($y);
    $sum_x2  = 0; $sum_x3 = 0; $sum_x4 = 0;
    $sum_xy  = 0; $sum_x2y = 0;

    for ($j = 0; $j < $n; $j++) {
        $xi = $x[$j];
        $yi = $y[$j];
        $xi2 = $xi * $xi;
        
        $sum_x2  += $xi2;
        $sum_x3  += $xi2 * $xi;
        $sum_x4  += $xi2 * $xi2;
        $sum_xy  += $xi * $yi;
        $sum_x2y += $xi2 * $yi;
    }

    // แก้สมการหาค่าเซ็ตระบบด้วย Cramer's Rule เพื่อหาค่าสัมประสิทธิ์ a, b, c
    $d = $n * ($sum_x2 * $sum_x4 - $sum_x3 * $sum_x3) - $sum_x * ($sum_x * $sum_x4 - $sum_x2 * $sum_x3) + $sum_x2 * ($sum_x * $sum_x3 - $sum_x2 * $sum_x2);
    
    if ($d == 0) {
        return false;
    }

    // หาค่า c (Intercept)
    $dc = $sum_y * ($sum_x2 * $sum_x4 - $sum_x3 * $sum_x3) - $sum_x * ($sum_xy * $sum_x4 - $sum_x3 * $sum_x2y) + $sum_x2 * ($sum_xy * $sum_x3 - $sum_x2 * $sum_x2y);
    // หาค่า b (X Coefficient)
    $db = $n * ($sum_xy * $sum_x4 - $sum_x2y * $sum_x3) - $sum_y * ($sum_x * $sum_x4 - $sum_x2 * $sum_x3) + $sum_x2 * ($sum_x * $sum_x2y - $sum_xy * $sum_x2);
    // หาค่า a (X^2 Coefficient)
    $da = $n * ($sum_x2 * $sum_x2y - $sum_xy * $sum_x3) - $sum_x * ($sum_x * $sum_x2y - $sum_xy * $sum_x2) + $sum_y * ($sum_x * $sum_x3 - $sum_x2 * $sum_x2);

    $c = $dc / $d;
    $b = $db / $d;
    $a = $da / $d;

    // คำนวณจุดบนเส้นโค้งในอดีต
    $historical_data = array();
    for ( $j = 0; $j < $n; $j++ ) {
        $current_x = $x[$j];
        $historical_data[] = array(
            'month'      => $months_label[$current_x],
            'actual'     => $y[$j],
            'regression' => ($a * $current_x * $current_x) + ($b * $current_x) + $c
        );
    }

    // ทำนายอนาคตล่วงหน้า 3 เดือน
    $forecast_data = array();
    $last_month_str = end( $months_label );
    
    // 💡 1. คำนวณค่ายอดขายเฉลี่ยในอดีต (Mean) และดึงค่ายอดขายเดือนล่าสุด (Last Month Actual) ไว้เป็นแผนสำรอง
    $average_sales = ( count( $y ) > 0 ) ? array_sum( $y ) / count( $y ) : 0;
    $last_actual_sales = end( $y ); // ยอดขายจริงของเดือนล่าสุด

    for ( $k = 1; $k <= 3; $k++ ) {
        $next_x = $n + $k;
        $next_month_str = date( 'Y-m', strtotime( $last_month_str . " +{$k} month" ) );
        
        // คำนวณตามสูตรพหุนามปกติ
        $pred_y = ($a * $next_x * $next_x) + ($b * $next_x) + $c;
        
        // 💡 2. ระบบดักเซฟตี้: ถ้า Polynomial เหวี่ยงจนยอดต่ำกว่า 10% ของยอดเฉลี่ย หรือติดลบ
        // เราจะสลับไปใช้ค่ายอดขายเดือนล่าสุด (หรือค่าเฉลี่ย) ประคองกราฟแทนทันที
        if ( $pred_y < ( $average_sales * 0.10 ) ) {
            // นายสามารถเลือกได้: 
            // - ใช้ $last_actual_sales (ทรงตัวเท่าเดือนล่าสุด) -> แนะนำ ทรงกราฟจะเนียนสุด
            // - ใช้ $average_sales (วิ่งเข้าหาค่าเฉลี่ยรวม)
            $final_forecast = $last_actual_sales; 
        } else {
            $final_forecast = $pred_y; // ถ้าค่าปกติ ไม่เอเรอร์ ก็ใช้ค่าที่โมเดลทำนายมา
        }
        
        $forecast_data[] = array(
            'month'      => $next_month_str . ' (ทำนาย)',
            'forecast'   => round( max( 0, $final_forecast ), 2 )
        );
    }

    return array(
        'historical' => $historical_data,
        'forecast'   => $forecast_data,
        'slope'      => $b, // ส่งตัวแปรหลอกไปให้หน้าบ้านรันได้ไม่พัง
        'intercept'  => $c,
        'poly_a'     => $a
    );
}

// 3. แสดงผลหน้า Dashboard สถิติหลังบ้าน
function wclrf_render_dashboard_page() {
    $data = wclrf_calculate_regression_data();
    ?>
    <div class="wrap">
        <?php if ( ! $data ) : ?>
            <div class="notice notice-warning"><p>ไม่พบข้อมูลยอดขายในระบบที่เพียงพอต่อการวิเคราะห์</p></div>
        <?php elseif ( isset( $data['error'] ) ) : ?>
            <div class="notice notice-error"><p><?php echo $data['error']; ?></p></div>
        <?php else : ?>

            <div style="display:flex; gap:15px; margin-bottom:20px;">
                <div style="background:#fff; padding:15px; border-left:4px solid #46b450; box-shadow:0 1px 1px rgba(0,0,0,.04); flex:1;">
                    <h3>แนวโน้มธุรกิจปัจจุบัน (Slope)</h3>
                    <p style="font-size:24px; font-weight:bold; margin:5px 0; color: <?php echo $data['slope'] >= 0 ? '#46b450' : '#dc3232'; ?>">
                        <?php echo $data['slope'] >= 0 ? '↗ ' : '↘ '; ?>
                        <?php echo number_format( abs($data['slope']), 2 ); ?> บาท / เดือน
                    </p>
                    <small class="text-muted">อัตราการเติบโตเฉลี่ยที่ขยับขึ้นหรือลดลงในทุกๆ เดือนที่ผ่านมา</small>
                </div>
                <div style="background:#fff; padding:15px; border-left:4px solid #0073aa; box-shadow:0 1px 1px rgba(0,0,0,.04); flex:1;">
                    <h3>สูตรสมการทำนายเส้นตรง</h3>
                    <p style="font-size:18px; font-family:monospace; margin:10px 0;">
                        y = (<?php echo number_format($data['poly_a'], 2); ?> * x²) + (<?php echo number_format($data['slope'], 2); ?> * x) + <?php echo number_format($data['intercept'], 2); ?>
                    </p>
                    <small class="text-muted">ใช้สมการคณิตศาสตร์เชิงเส้นขั้นพื้นฐานในการลากเส้น Polynomial Regression</small>
                </div>
            </div>

            <?php
            // === 1. จัดเตรียมข้อมูลสำหรับวาดกราฟให้แยกชุดกันชัดเจน ===
            $labels = array();
            $actual_sales = array();
            $regression_line = array();

            // ใส่ข้อมูลฝั่งอดีต (12 เดือนล่าสุด)
            foreach ( $data['historical'] as $row ) {
                $labels[]          = $row['month'];
                $actual_sales[]    = $row['actual'];
                $regression_line[] = round($row['regression'], 2);
            }

            // ใส่ข้อมูลฝั่งพยากรณ์อนาคต (3 เดือนข้างหน้า)
            foreach ( $data['forecast'] as $row ) {
                $labels[]          = $row['month'];
                // 💡 เคล็ดลับ: ใช้ NaN หรือไม่ต้องส่งค่าเข้าไปในอาร์เรย์แท่ง เพื่อป้องกันกราฟเอ๋อ
                $actual_sales[]    = null; 
                $regression_line[] = round($row['forecast'], 2);
            }
            ?>

            <div style="background: #fff; padding: 20px; margin-bottom: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <div style="position: relative; width: 100%; height: 400px;">
                    <canvas id="wclrfSalesChart"></canvas>
                </div>
            </div>

            <script>
            document.addEventListener("DOMContentLoaded", function() {
                const ctx = document.getElementById('wclrfSalesChart');
                
                if (ctx) {
                    // ดึงค่า JSON จากฝั่ง PHP ที่เราเตรียมไว้
                    const chartLabels = <?php echo json_encode( $labels ); ?>;
                    const actualData = <?php echo json_encode( $actual_sales ); ?>;
                    const regressionData = <?php echo json_encode( $regression_line ); ?>;

                    new Chart(ctx, {
                        data: {
                            labels: chartLabels,
                            datasets: [
                                {
                                    type: 'bar', // กราฟแท่งสีน้ำเงิน สำหรับยอดขายจริง
                                    label: 'ยอดขายจริง (Actual Sales)',
                                    data: actualData,
                                    backgroundColor: 'rgba(54, 162, 235, 0.6)',
                                    borderColor: 'rgba(54, 162, 235, 1)',
                                    borderWidth: 1,
                                    order: 2 // ให้แท่งอยู่เลเยอร์หลัง
                                },
                                {
                                    type: 'line', // กราฟเส้นตรง/เส้นโค้ง สำหรับสถิติทำนาย
                                    label: 'เส้นแนวโน้มพหุนาม (Polynomial Trend)',
                                    data: regressionData,
                                    backgroundColor: 'transparent',
                                    borderColor: 'rgba(255, 99, 132, 1)',
                                    borderWidth: 3,
                                    pointBackgroundColor: 'rgba(255, 99, 132, 1)',
                                    pointRadius: 4,
                                    tension: 0.1, // ดัดความสมูทของเส้นโค้งพหุนามนิดนึงไม่ให้แข็งเกินไป
                                    order: 1, // ให้เส้นอยู่เลเยอร์หน้าสุด
                                    // 💡 แยกจุดไข่ปลา: ถ้าอยากให้สวยๆ สามารถคุมเส้นประได้
                                    borderDash: [5, 5] 
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false, // บังคับให้ขยายเต็มความสูง 400px ที่ราดกรอบไว้
                            plugins: {
                                legend: {
                                    position: 'top',
                                },
                                title: {
                                    display: true,
                                    text: 'กราฟวิเคราะห์ทิศทางด้วย Polynomial Regression'
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return value.toLocaleString() + ' บาท';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            });
            </script>

            <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                <div style="flex: 2; min-width: 400px;">
                    <div style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                        <h3>📊 ยอดขายจริง VS เส้นจำลองสถิติ (12 เดือนล่าสุด)</h3>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>เดือน</th>
                                    <th>ยอดขายจริง (Actual)</th>
                                    <th>ค่าบนเส้นสถิติ (Regression)</th>
                                    <th>ผลต่างความคลาดเคลื่อน</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $data['historical'] as $row ) : 
                                    $diff = $row['actual'] - $row['regression'];
                                ?>
                                <tr>
                                    <td><strong><?php echo $row['month']; ?></strong></td>
                                    <td><?php echo number_format( $row['actual'], 2 ); ?> บาท</td>
                                    <td style="color:#666; font-style:italic;"><?php echo number_format( $row['regression'], 2 ); ?> บาท</td>
                                    <td style="color: <?php echo $diff >= 0 ? 'green' : 'red'; ?>">
                                        <?php echo $diff >= 0 ? '+' : ''; ?><?php echo number_format( $diff, 2 ); ?> บาท
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div style="flex: 1; min-width: 280px;">
                    <div style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); border-top: 4px solid #ffb900;">
                        <h3>🔮 ผลการทำนายยอดขาย (3 เดือนข้างหน้า)</h3>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>เดือนอนาคต</th>
                                    <th>ยอดคาดการณ์ (Forecast)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $data['forecast'] as $row ) : ?>
                                <tr style="background: #fffdf5;">
                                    <td><strong><?php echo $row['month']; ?></strong></td>
                                    <td style="font-size:15px; font-weight:bold; color: #d54e21;">
                                        ฿ <?php echo number_format( $row['forecast'], 2 ); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="description" style="margin-top: 15px;">* หมายเหตุ: เป็นการคำนวณทิศทางจากสถิติแบบเส้นตรงล้วนๆ ไม่ได้คำนวณปัจจัยประเภทช่วงเทศกาล (Seasonal Effect) หรือแคมเปญโปรโมชั่นเสริม</p>
                    </div>
                </div>
            </div>

        <?php endif; ?>
    </div>
    <?php
}