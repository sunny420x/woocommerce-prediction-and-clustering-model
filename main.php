<?php
/**
 * Plugin Name: WooCommerce Sales Prediction And Customer Clustering Model.
 * Description: ทำนายยอดขายรายเดือนล่วงหน้า (Polynomial Regression) และจัดกลุ่มลูกค้า (K-Means Clustering)
 * Author: Jirakit Pawnsakunrungrot
 * Author URI: https://www.linkedin.com/in/sunny-jirakit
 * Plugin URI: https://github.com/sunny420x/woocommerce-prediction-and-clustering-model
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
        'ทำนายยอดขาย Polynomial Regression',
        'ทำนายยอดขาย Polynomial Regression',
        'manage_options',
        'sales-forecast',
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
function calculate_regression_data() {
    global $wpdb;

    if(isset($_GET['month'])) {
        $month = $_GET['month'];
    } else {
        $month = get_option('default_month_training_set', 12);
    }

    // ดึงข้อมูลออเดอร์ย้อนหลังตามจำนวนเดือนที่กำหนด
    $query = $wpdb->prepare("
        SELECT 
            DATE_FORMAT(p.post_date, '%Y-%m') as sales_month,
            SUM(meta.meta_value) as total_sales
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} meta ON p.ID = meta.post_id
        WHERE p.post_type = 'shop_order'
          AND p.post_status IN ('wc-completed', 'wc-processing')
          AND meta.meta_key = '_order_total'
          AND p.post_date >= DATE_SUB(DATE_FORMAT(NOW() ,'%Y-%m-01'), INTERVAL %d MONTH)
          AND p.post_date < DATE_FORMAT(NOW() ,'%Y-%m-01')
        GROUP BY sales_month
        ORDER BY sales_month ASC
    ", $month);

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

    $sum_squared_error = 0; // SSres
    $ss_tot = 0;
    
    // หาค่าเฉลี่ยยอดขายจริง (Mean Actual)
    $mean_actual = ($n > 0) ? ($sum_y / $n) : 0;

    foreach ( $historical_data as $row ) {
        // 1. หาผลรวมความคลาดเคลื่อนกำลังสอง (SSres)
        $sum_squared_error += pow(($row['actual'] - $row['regression']), 2);
        
        // 2. หาผลรวมความแปรปรวนจากค่าเฉลี่ยรวม (SStot)
        $ss_tot += pow(($row['actual'] - $mean_actual), 2);
    }

    // 3. เข้าสูตรสถิติ R² = 1 - (SSres / SStot)
    $r_squared = ($ss_tot > 0) ? (1 - ($sum_squared_error / $ss_tot)) : 1;
    $r_squared_percentage = $r_squared * 100;
    // -------------------------------------------------------------

    // ทำนายอนาคตล่วงหน้า 3 เดือน
    $forecast_data = array();
    $last_month_str = end( $months_label );
    
    // 1. คำนวณค่ายอดขายเฉลี่ยในอดีต (Mean) และดึงค่ายอดขายเดือนล่าสุด (Last Month Actual) ไว้เป็นแผนสำรอง
    $average_sales = ( count( $y ) > 0 ) ? array_sum( $y ) / count( $y ) : 0;
    $last_actual_sales = end( $y ); // ยอดขายจริงของเดือนล่าสุด

    for ( $k = 1; $k <= 3; $k++ ) {
        $next_x = $n + $k;
        $next_month_str = date( 'Y-m', strtotime( $last_month_str . " +{$k} month" ) );
        
        // คำนวณตามสูตรพหุนามปกติ
        $pred_y = ($a * $next_x * $next_x) + ($b * $next_x) + $c;
        
        // 2. ระบบดักเซฟตี้: ถ้า Polynomial เหวี่ยงจนยอดต่ำกว่า 10% ของยอดเฉลี่ย หรือติดลบ
        // เราจะสลับไปใช้ค่ายอดขายเดือนล่าสุด (หรือค่าเฉลี่ย) ประคองกราฟแทนทันที
        if ( $pred_y < ( $average_sales * 0.10 ) ) {
            $final_forecast = $last_actual_sales; 
        } else {
            $final_forecast = $pred_y; 
        }
        
        $forecast_data[] = array(
            'month'      => $next_month_str . ' (ทำนาย)',
            'forecast'   => round( max( 0, $final_forecast ), 2 )
        );
    }

    return array(
        'historical'           => $historical_data,
        'forecast'             => $forecast_data,
        'slope'                => $b, 
        'intercept'            => $c,
        'poly_a'               => $a,
        'r_squared_percentage' => $r_squared_percentage 
    );
}

add_action('admin_init', 'regression_setting_init');

function regression_setting_init()
{
    register_setting('regression_setting_group', 'default_month_training_set');
}

// 3. แสดงผลหน้า Dashboard สถิติหลังบ้าน
function wclrf_render_dashboard_page() {
    $data = calculate_regression_data();
    ?>
    <style>
    @media print {
        .no-print {
            display: none !important;
        }

        body {
            transform: scale(1);
        }
    }
    </style>
    <div class="wrap">
        <?php if ( ! $data ) : ?>
            <div class="notice notice-warning"><p>ไม่พบข้อมูลยอดขายในระบบที่เพียงพอต่อการวิเคราะห์</p></div>
        <?php elseif ( isset( $data['error'] ) ) : ?>
            <div class="notice notice-error"><p><?php echo $data['error']; ?></p></div>
        <?php else : ?>
            <p style="font-size: 18px;">วันที่ออกรายงาน: <?=date("d/m/Y");?> <button class="button button-small no-print" onclick="window.print()">พิมพ์หน้านี้</button></p>
            จำนวนเดือน (ไม่ต่ำกว่า 3 เดือน): <input type="number" value="<?=$_GET['month'] ?? get_option('default_month_training_set', 12)?>" step="1" onchange="window.location.href='admin.php?page=sales-forecast&month='+this.value"> เดือน<br><br>
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
                <div style="flex: 2; min-width: 400px;" class="no-print">
                    <div style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                        <h3>📊 ยอดขายจริง VS เส้นจำลองสถิติ</h3>
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
                                <?php 
                                    foreach ( $data['historical'] as $row ) : 
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
                                <?php endforeach; 
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div style="flex: 1; min-width: 280px;">
                    <div style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); border-top: 4px solid #ffb900;">
                        <h3>🔮 ผลการทำนายยอดขาย (3 เดือนข้างหน้า) ค่า R Squared = <?=bcdiv($data['r_squared_percentage'], 1, 2);?>%</h3>
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
                    <div style="background: #fff; padding: 20px; margin-top: 20px; width: max-content;" class="no-print">
                        <h1>ตั้งค่าระบบ</h1>
                        <form action="options.php" method="post">
                            <?php
                            settings_fields('regression_setting_group');
                            ?>
                            จำนวนเดือนที่ต้องการ Train: <input type="number" name="default_month_training_set" value="<?=get_option('default_month_training_set', 12)?>"> เดือน
                            <button type="submit" class="button">บันทึกการเปลี่ยนแปลง</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * 1. ลงทะเบียนเพิ่ม Widget ในหน้า Dashboard ของ WordPress
 */
add_action('wp_dashboard_setup', 'register_forecast_dashboard_widget');

function register_forecast_dashboard_widget() {
    // ดึงชื่อเว็บมาทำเป็น ID หรือตั้งชื่อเท่ๆ ได้เลย
    wp_add_dashboard_widget(
        'sales_forecast_widget',                 // Widget Slug ID
        '🔮 ระบบทำนายยอดขาย (Regression)',      // ชื่อหัวข้อที่จะโชว์บนแผงควบคุม
        'render_forecast_dashboard_widget'       // ฟังก์ชันที่จะใช้พ่น HTML ออกมา
    );
}

/**
 * 2. ฟังก์ชันแสดงผลและจัดการระบบ Cache (Transient API)
 */
function render_forecast_dashboard_widget() {
    // ตั้งชื่อคีย์สำหรับเก็บแคช (แยกตามชื่อเว็บ จะได้ไม่ตีกันถ้าก๊อปไปใช้หลายที่)
    $cache_key = 'sales_forecast_cache_data';
    
    // ตั้งเวลาหมดอายุแคช: 1 วัน (1 วัน * 24 ชั่วโมง * 60 นาที * 60 วินาที)
    $cache_expiration = 1 * DAY_IN_SECONDS; 

    $cached_data = get_transient($cache_key);

    if ( false === $cached_data ) {
        if ( function_exists('calculate_regression_data') ) {
            $data = calculate_regression_data(); 
        } else {
            // ดักไว้กันพังชั่วคราว เผื่อเรียกหาฟังก์ชันไม่เจอ
            echo "<p style='color:red;'>ไม่พบฟังก์ชันสำหรับคำนวณข้อมูลยอดขาย</p>";
            return;
        }

        // นำค่า R-Squared ที่คำนวณได้สดๆ ร้อนๆ มาเก็บพ่วงไปด้วย
        // (สมมุติว่าฟังก์ชันนายส่งค่า $r_squared_percentage กลับมาในอาร์เรย์ด้วย)
        $cached_data = [
            'r_squared' => isset($data['r_squared_percentage']) ? $data['r_squared_percentage'] : 0,
            'forecast'  => isset($data['forecast']) ? $data['forecast'] : []
        ];

        set_transient($cache_key, $cached_data, $cache_expiration);
        
        echo "";
    } else {
        echo "";
    }
    $r_squared_percentage = $cached_data['r_squared'];
    $forecast_list        = $cached_data['forecast'];
    ?>
    <div style="background: #fffdf5; padding: 12px; border-left: 4px solid #ffb900; margin-bottom: 10px;">
        <h4 style="margin: 0 0 5px 0; font-size: 14px;">
            ความแม่นยำโมเดล (R-Squared): 
            <span style="color:#46b450; font-weight:bold;">
                <?php echo number_format($r_squared_percentage, 2); ?>%
            </span>
        </h4>
    </div>
    <table class="wp-list-table widefat fixed striped" style="box-shadow: none; border: 1px solid #ccd0d4;">
        <thead>
            <tr>
                <th style="padding: 8px 10px; font-weight: bold;">เดือนอนาคต</th>
                <th style="padding: 8px 10px; font-weight: bold;">ยอดคาดการณ์ (Forecast)</th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! empty($forecast_list) ) : ?>
                <?php foreach ( $forecast_list as $row ) : ?>
                <tr>
                    <td style="padding: 8px 10px;"><strong><?php echo esc_html($row['month']); ?></strong></td>
                    <td style="padding: 8px 10px; font-size:14px; font-weight:bold; color: #d54e21;">
                        ฿ <?php echo number_format( $row['forecast'], 2 ); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr>
                    <td colspan="2" style="text-align:center; padding:10px; color:#999;">ไม่มีข้อมูลการทำนาย</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    
    <p class="description" style="margin-top: 10px; font-size: 11px; line-height: 1.4;">
        * หมายเหตุ: เก็บข้อมูลแคชไว้ 10 วันเพื่อความรวดเร็ว | คำนวณตามโมเดลพฤติกรรมเฉพาะตัวของร้านค้า ไม่รวมปัจจัยโปรโมชั่นเสริมภายนอก
    </p>
    <?php
}

add_action( 'admin_menu', 'wckmc_register_admin_menu' );
function wckmc_register_admin_menu() {
    $page_hook = add_submenu_page(
        'woocommerce',
        'จัดกลุ่มลูกค้า Customer Clusters/Segmentation',
        'จัดกลุ่มลูกค้า Customer Clusters',
        'manage_options',
        'wc-customer-clustering',
        'wckmc_render_clustering_page'
    );
    
    // บังคับโหลด Chart.js ที่ Header ในหน้านี้
    add_action( 'admin_print_scripts-' . $page_hook, 'wckmc_enqueue_chart_js' );
}

function wckmc_enqueue_chart_js() {
    wp_enqueue_script('chart-js-cdn', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.1', false);
}

// 2. K-Means Clustering Engine (PHP Native)
function wckmc_run_kmeans_clustering($k = 3, $max_iterations = 100) {
    global $wpdb;

    // ดึงข้อมูลพฤติกรรมลูกค้า: ID, จำนวนออเดอร์ (Frequency), ยอดซื้อรวม (Monetary)
    $query = "
        SELECT 
            p.meta_value as customer_id,
            COUNT(p.post_id) as frequency,
            SUM(m.meta_value) as monetary
        FROM {$wpdb->postmeta} p
        INNER JOIN {$wpdb->postmeta} m ON p.post_id = m.post_id
        INNER JOIN {$wpdb->posts} posts ON p.post_id = posts.ID
        WHERE p.meta_key = '_customer_user'
          AND m.meta_key = '_order_total'
          AND posts.post_status IN ('wc-completed', 'wc-processing')
          AND p.meta_value > 0
        GROUP BY customer_id
    ";

    $customers = $wpdb->get_results($query, ARRAY_A);

    if (empty($customers) || count($customers) < $k) {
        return false;
    }

    // ฟอร์แมตข้อมูลดิบให้อยู่ในรูป Array พิกัด [X = Frequency, Y = Monetary]
    $data_points = array();
    foreach ($customers as $c) {
        $user_info = get_userdata($c['customer_id']);
        $name = $user_info ? $user_info->display_name : 'Guest #' . $c['customer_id'];
        
        $data_points[$c['customer_id']] = array(
            'id'   => $c['customer_id'],
            'name' => $name,
            'x'    => (int)$c['frequency'],
            'y'    => (float)$c['monetary']
        );
    }

    // --- เริ่มต้นอัลกอริทึม K-Means ---
    
    // สุ่มจุดศูนย์กลางเริ่มต้น (Centroids Initialization)
    $centroids = array();
    $random_keys = array_rand($data_points, $k);
    foreach ((array)$random_keys as $index => $key) {
        $centroids[$index] = array('x' => $data_points[$key]['x'], 'y' => $data_points[$key]['y']);
    }

    $assignments = array();

    // ลูปประมวลผลจนกว่าจุดศูนย์กลางจะนิ่ง หรือครบจำนวนรอบสูงสุด
    for ($iter = 0; $iter < $max_iterations; $iter++) {
        $new_assignments = array();
        $cluster_sums = array_fill(0, $k, array('x' => 0, 'y' => 0, 'count' => 0));

        // สเต็ปที่ A: หาว่าลูกค้าแต่ละคน อยู่ใกล้จุดศูนย์กลางไหนที่สุด (Euclidean Distance)
        foreach ($data_points as $id => $point) {
            $min_dist = INF;
            $closest_cluster = 0;

            foreach ($centroids as $cluster_id => $centroid) {
                // สูตรระยะห่าง: sqrt((x2-x1)^2 + (y2-y1)^2)
                $dist = sqrt(pow($point['x'] - $centroid['x'], 2) + pow($point['y'] - $centroid['y'], 2));
                if ($dist < $min_dist) {
                    $min_dist = $dist;
                    $closest_cluster = $cluster_id;
                }
            }

            $new_assignments[$id] = $closest_cluster;
            
            // เก็บสะสมยอดรวมเพื่อใช้วิเคราะห์ค่าเฉลี่ยจุดศูนย์กลางใหม่
            $cluster_sums[$closest_cluster]['x'] += $point['x'];
            $cluster_sums[$closest_cluster]['y'] += $point['y'];
            $cluster_sums[$closest_cluster]['count']++;
        }

        // เช็คว่ากลุ่มนิ่งหรือยัง (ถ้าไม่มีลูกค้าคนไหนย้ายกลุ่มเลยให้หยุดลูปทันที)
        if ($new_assignments === $assignments) {
            break;
        }
        $assignments = $new_assignments;

        // สเต็ปที่ B: คำนวณหาจุดศูนย์กลางใหม่จากค่าเฉลี่ยของสมาชิกในกลุ่ม (Update Centroids)
        foreach ($centroids as $cluster_id => $centroid) {
            if ($cluster_sums[$cluster_id]['count'] > 0) {
                $centroids[$cluster_id]['x'] = $cluster_sums[$cluster_id]['x'] / $cluster_sums[$cluster_id]['count'];
                $centroids[$cluster_id]['y'] = $cluster_sums[$cluster_id]['y'] / $cluster_sums[$cluster_id]['count'];
            }
        }
    }

    // จัดกลุ่มผลลัพธ์เพื่อส่งออกไปแสดงผลหน้าบ้าน
    $clustered_results = array_fill(0, $k, array());
    foreach ($data_points as $id => $point) {
        $cluster_id = $assignments[$id];
        $point['cluster'] = $cluster_id;
        $clustered_results[$cluster_id][] = $point;
    }

    return array(
        'clusters'  => $clustered_results,
        'centroids' => $centroids
    );
}

// 3. หน้า Dashboard แสดงกราฟและตารางรายชื่อลูกค้า
function wckmc_render_clustering_page() {
    // สั่งรันจัดกลุ่มลูกค้าเป็น 3 กลุ่ม (สามารถเปลี่ยนตัวเลขได้ตามใจชอบ)
    $k = 3;
    $result = wckmc_run_kmeans_clustering($k);
    
    // นิยามป้ายกำกับกลุ่มแบบเข้าใจง่าย
    $cluster_labels = array(
        0 => 'กลุ่มที่ 1',
        1 => 'กลุ่มที่ 2',
        2 => 'กลุ่มที่ 3'
    );

    // เซ็ตคู่สีประจำกลุ่มสำหรับวาดกราฟ Scatter Plot
    $cluster_colors = array(
        0 => 'rgba(255, 99, 132, 0.7)',  // สีแดงอมชมพู
        1 => 'rgba(54, 162, 235, 0.7)',  // สีน้ำเงิน
        2 => 'rgba(75, 192, 192, 0.7)'   // สีเขียวมินต์
    );
    ?>
    <style>
    @media print {
        .no-print {
            display: none !important;
        }

        body {
            transform: scale(1);
        }
    }
    </style>
    <div class="wrap">
        <?php if (!$result) : ?>
            <div class="notice notice-warning"><p>ข้อมูลลูกค้าและออเดอร์ในระบบมีไม่เพียงพอต่อการจัดกลุ่มสถิติ</p></div>
        <?php else : ?>
            <p style="font-size: 18px;">วันที่ออกรายงาน: <?=date("d/m/Y");?> <button class="button button-small no-print" onclick="window.print()">พิมพ์หน้านี้</button></p>
            <div style="background: #fff; padding: 20px; margin-bottom: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <div style="position: relative; width: 100%; height: 450px;">
                    <canvas id="wckmcClusterChart"></canvas>
                </div>
            </div>

            <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                <?php foreach ($result['clusters'] as $cluster_id => $members) : ?>
                    <div style="flex: 1; min-width: 300px; background: #fff; padding: 15px; border: 1px solid #ccd0d4; border-top: 4px solid <?php echo $cluster_colors[$cluster_id]; ?>;">
                        <h3><?php echo isset($cluster_labels[$cluster_id]) ? $cluster_labels[$cluster_id] : 'กลุ่มที่ ' . ($cluster_id + 1); ?></h3>
                        <p>จำนวนสมาชิกในกลุ่ม: <strong><?php echo count($members); ?></strong> คน</p>
                        
                        <div style="max-height: 500px; overflow-y: auto;">
                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th>ชื่อลูกค้า</th>
                                        <th>จำนวนครั้ง (F)</th>
                                        <th>ยอดรวม (M)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($members as $member) : ?>
                                        <tr>
                                            <td><strong><?php echo esc_html($member['name']); ?></strong></td>
                                            <td><?php echo $member['x']; ?> ครั้ง</td>
                                            <td><?php echo number_format($member['y']); ?> ฿</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <script>
            document.addEventListener("DOMContentLoaded", function() {
                const ctx = document.getElementById('wckmcClusterChart');
                if (!ctx) return;

                // ดึงข้อมูลกลุ่มและคู่สีจากฝั่ง PHP
                const clusterColors = <?php echo json_encode($cluster_colors); ?>;
                const rawClusters = <?php echo json_encode($result['clusters']); ?>;
                const clusterLabels = <?php echo json_encode($cluster_labels); ?>;
                
                // แปลงข้อมูลให้อยู่ในฟอร์แมต Datasets ของ Chart.js Scatter
                const datasets = Object.keys(rawClusters).map(clusterId => {
                    return {
                        label: clusterLabels[clusterId] || 'Cluster ' + clusterId,
                        data: rawClusters[clusterId].map(item => ({ x: item.x, y: item.y, labelName: item.name })),
                        backgroundColor: clusterColors[clusterId],
                        pointRadius: 6,
                        pointHoverRadius: 8
                    };
                });

                new Chart(ctx, {
                    type: 'scatter', // ใช้กราฟกระจายพิกัด XY
                    data: { datasets: datasets },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    // ปรับแต่งหน้าต่าง Tooltip เวลาเอาเมาส์ไปชี้ที่จุดให้โชว์ชื่อลูกค้าด้วย
                                    label: function(context) {
                                        const rawPoint = context.raw;
                                        return rawPoint.labelName + ' (ซื้อ ' + rawPoint.x + ' ครั้ง, ยอดรวม ' + rawPoint.y.toLocaleString() + ' บาท)';
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                title: { display: true, text: 'ความถี่ในการซื้อ (Frequency - จำนวนครั้ง)', font: { weight: 'bold' } },
                                beginAtZero: true
                            },
                            y: {
                                title: { display: true, text: 'ยอดซื้อสะสม (Monetary - บาท)', font: { weight: 'bold' } },
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) { return value.toLocaleString() + ' ฿'; }
                                }
                            }
                        }
                    }
                });
            });
            </script>

        <?php endif; ?>
    </div>
    <?php
}