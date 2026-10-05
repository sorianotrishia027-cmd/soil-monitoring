<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include "../config/db_connect.php";

$role = strtolower(trim($_SESSION['role'] ?? ''));
$user_id = intval($_SESSION['user_id'] ?? 0);

$latest = false;

/*
|--------------------------------------------------------------------------
| GET LATEST TELEMETRY
|--------------------------------------------------------------------------
| Admin  = latest system-wide reading
| Farmer = ONLY the logged-in farmer's own reading
|--------------------------------------------------------------------------
*/

if ($role === 'admin') {

    $stmt = $conn->prepare("
        SELECT *
        FROM sensor_data
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute();
    $latest = $stmt->fetch(PDO::FETCH_ASSOC);

} elseif ($role === 'farmer' && $user_id > 0) {

    $stmt = $conn->prepare("
        SELECT *
        FROM sensor_data
        WHERE user_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute([$user_id]);
    $latest = $stmt->fetch(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| DEFAULT VALUES
|--------------------------------------------------------------------------
*/

$hasTelemetry = !empty($latest);

$moisture = $hasTelemetry && isset($latest['moisture'])
    ? floatval($latest['moisture'])
    : null;

$ph = $hasTelemetry && isset($latest['ph_level'])
    ? floatval($latest['ph_level'])
    : null;

$nitrogen = $hasTelemetry && isset($latest['nitrogen'])
    ? floatval($latest['nitrogen'])
    : null;

$phosphorus = $hasTelemetry && isset($latest['phosphorus'])
    ? floatval($latest['phosphorus'])
    : null;

$potassium = $hasTelemetry && isset($latest['potassium'])
    ? floatval($latest['potassium'])
    : null;

$temperature = $hasTelemetry && isset($latest['temperature'])
    ? floatval($latest['temperature'])
    : null;

$status = $hasTelemetry && isset($latest['status'])
    ? strtoupper(trim($latest['status']))
    : '';

$recommendationCount = 0;
$criticalCount = 0;
$warningCount = 0;
$goodCount = 0;

?>

<div class="sub-view-panel">

<h2>Recommendations</h2>

<?php if (!$hasTelemetry): ?>

    <div class="recommendation-empty">
        <h3>No Telemetry Data Available</h3>

        <?php if ($role === 'farmer'): ?>

            <p>
                There is currently no soil telemetry data available for
                your assigned account or node.
            </p>

            <p>
                Once your ESP32 soil-monitoring device sends data,
                personalized farming recommendations will appear here.
            </p>

        <?php elseif ($role === 'admin'): ?>

            <p>
                There is currently no telemetry data available in the system.
            </p>

            <p>
                Recommendations will appear once the soil-monitoring
                device starts sending readings.
            </p>

        <?php else: ?>

            <p>
                Your account does not have permission to view telemetry
                recommendations.
            </p>

        <?php endif; ?>
    </div>

<?php else: ?>

    <!-- =========================================================
         CURRENT SOIL CONDITION
    ========================================================== -->

    <div class="recommendation-section">

        <h3>Current Soil Condition</h3>

        <div class="recommendation-readings">

            <?php if ($moisture !== null): ?>
                <div class="reading-card">
                    <strong>Soil Moisture</strong>
                    <span>
                        <?= number_format($moisture, 1) ?>%
                    </span>

                    <?php if ($moisture < 30): ?>
                        <small>LOW</small>
                    <?php elseif ($moisture > 60): ?>
                        <small>HIGH</small>
                    <?php else: ?>
                        <small>ACCEPTABLE</small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>


            <?php if ($ph !== null): ?>
                <div class="reading-card">
                    <strong>Soil pH</strong>
                    <span>
                        <?= number_format($ph, 2) ?>
                    </span>

                    <?php if ($ph < 5.0): ?>
                        <small>ACIDIC</small>
                    <?php elseif ($ph > 7.5): ?>
                        <small>ALKALINE</small>
                    <?php else: ?>
                        <small>ACCEPTABLE</small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>


            <?php if ($nitrogen !== null): ?>
                <div class="reading-card">
                    <strong>Nitrogen</strong>
                    <span>
                        <?= number_format($nitrogen, 1) ?> mg/kg
                    </span>

                    <?php if ($nitrogen < 20): ?>
                        <small>LOW</small>
                    <?php else: ?>
                        <small>ADEQUATE</small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>


            <?php if ($phosphorus !== null): ?>
                <div class="reading-card">
                    <strong>Phosphorus</strong>
                    <span>
                        <?= number_format($phosphorus, 1) ?> mg/kg
                    </span>

                    <?php if ($phosphorus < 10): ?>
                        <small>LOW</small>
                    <?php else: ?>
                        <small>ADEQUATE</small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>


            <?php if ($potassium !== null): ?>
                <div class="reading-card">
                    <strong>Potassium</strong>
                    <span>
                        <?= number_format($potassium, 1) ?> mg/kg
                    </span>

                    <?php if ($potassium < 15): ?>
                        <small>LOW</small>
                    <?php else: ?>
                        <small>ADEQUATE</small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>


            <?php if ($temperature !== null): ?>
                <div class="reading-card">
                    <strong>Temperature</strong>
                    <span>
                        <?= number_format($temperature, 1) ?> °C
                    </span>

                    <?php if ($temperature > 35): ?>
                        <small>HIGH</small>
                    <?php elseif ($temperature >= 32.5): ?>
                        <small>WARM</small>
                    <?php else: ?>
                        <small>NORMAL</small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>

    </div>


    <!-- =========================================================
         FARMER RECOMMENDATIONS
    ========================================================== -->

    <?php if ($role === 'farmer'): ?>

        <div class="recommendation-section">

            <h3>Personalized Farming Recommendations</h3>

            <p class="recommendation-intro">
                The recommendations below are based on the latest soil
                readings associated with your account.
            </p>


            <!-- =================================================
                 MOISTURE
            ================================================== -->

            <?php if ($moisture !== null && $moisture < 30): ?>

                <?php
                $recommendationCount++;
                $criticalCount++;
                ?>

                <div class="recommendation-item critical">

                    <h4>1. Soil Moisture is Low</h4>

                    <p>
                        The soil moisture level is
                        <strong><?= number_format($moisture, 1) ?>%</strong>,
                        which is below the recommended monitoring range.
                    </p>

                    <strong>Recommended actions:</strong>

                    <ul>
                        <li>
                            Irrigate the field gradually instead of applying
                            a large amount of water at once.
                        </li>

                        <li>
                            Make sure the root zone receives enough moisture
                            before stopping irrigation.
                        </li>

                        <li>
                            Check the soil condition again after irrigation.
                        </li>

                        <li>
                            Monitor the moisture reading more frequently
                            during hot or dry periods.
                        </li>

                        <li>
                            Check for damaged irrigation lines, blocked
                            water sources, or uneven water distribution.
                        </li>

                        <li>
                            Avoid excessive flooding because very wet soil
                            can create another set of problems.
                        </li>
                    </ul>

                </div>

            <?php elseif ($moisture !== null && $moisture > 60): ?>

                <?php
                $recommendationCount++;
                $warningCount++;
                ?>

                <div class="recommendation-item warning">

                    <h4>1. Soil Moisture is High</h4>

                    <p>
                        The soil moisture level is
                        <strong><?= number_format($moisture, 1) ?>%</strong>,
                        which indicates that the field may be receiving
                        more water than necessary.
                    </p>

                    <strong>Recommended actions:</strong>

                    <ul>
                        <li>
                            Reduce or temporarily stop irrigation.
                        </li>

                        <li>
                            Check the field for standing water.
                        </li>

                        <li>
                            Inspect drainage channels and make sure water
                            can leave the field properly.
                        </li>

                        <li>
                            Avoid unnecessary additional watering until
                            the moisture level decreases.
                        </li>

                        <li>
                            Monitor the next moisture readings before
                            resuming the normal irrigation schedule.
                        </li>
                    </ul>

                </div>

            <?php else: ?>

                <?php
                if ($moisture !== null) {
                    $goodCount++;
                }
                ?>

                <div class="recommendation-item good">

                    <h4>1. Soil Moisture is Acceptable</h4>

                    <p>
                        The current moisture reading is
                        <strong><?= number_format($moisture, 1) ?>%</strong>.
                        Continue the current irrigation management while
                        monitoring changes in soil conditions.
                    </p>

                    <ul>
                        <li>
                            Maintain the normal irrigation schedule.
                        </li>

                        <li>
                            Avoid unnecessary watering.
                        </li>

                        <li>
                            Continue checking moisture during hot or dry
                            weather.
                        </li>
                    </ul>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 pH
            ================================================== -->

            <?php if ($ph !== null && $ph < 5.0): ?>

                <?php
                $recommendationCount++;
                $criticalCount++;
                ?>

                <div class="recommendation-item critical">

                    <h4>2. Soil pH is Too Acidic</h4>

                    <p>
                        The soil pH is
                        <strong><?= number_format($ph, 2) ?></strong>,
                        indicating acidic soil conditions.
                    </p>

                    <strong>Recommended actions:</strong>

                    <ul>
                        <li>
                            Consider agricultural lime or dolomite to help
                            raise soil pH.
                        </li>

                        <li>
                            Use the application rate recommended by a soil
                            test or qualified agricultural adviser.
                        </li>

                        <li>
                            Avoid applying excessive lime because very high
                            pH can also reduce nutrient availability.
                        </li>

                        <li>
                            Apply and incorporate the material properly
                            according to local agricultural guidance.
                        </li>

                        <li>
                            Continue monitoring the pH after soil treatment.
                        </li>
                    </ul>

                </div>

            <?php elseif ($ph !== null && $ph > 7.5): ?>

                <?php
                $recommendationCount++;
                $warningCount++;
                ?>

                <div class="recommendation-item warning">

                    <h4>2. Soil pH is Too Alkaline</h4>

                    <p>
                        The soil pH is
                        <strong><?= number_format($ph, 2) ?></strong>,
                        indicating alkaline soil conditions.
                    </p>

                    <strong>Recommended actions:</strong>

                    <ul>
                        <li>
                            Avoid materials that may further increase
                            soil alkalinity.
                        </li>

                        <li>
                            Consider adding suitable organic matter such as
                            well-rotted compost or manure when appropriate.
                        </li>

                        <li>
                            Follow soil-test recommendations before applying
                            soil amendments.
                        </li>

                        <li>
                            Monitor pH regularly to determine whether the
                            soil condition is improving.
                        </li>
                    </ul>

                </div>

            <?php else: ?>

                <?php
                if ($ph !== null) {
                    $goodCount++;
                }
                ?>

                <div class="recommendation-item good">

                    <h4>2. Soil pH is Acceptable</h4>

                    <p>
                        The current pH reading is
                        <strong><?= number_format($ph, 2) ?></strong>.
                        No immediate pH correction is indicated by the
                        current reading.
                    </p>

                    <ul>
                        <li>
                            Continue normal soil management.
                        </li>

                        <li>
                            Avoid unnecessary application of lime or other
                            pH-changing materials.
                        </li>

                        <li>
                            Continue periodic pH monitoring.
                        </li>
                    </ul>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 NITROGEN
            ================================================== -->

            <?php if ($nitrogen !== null && $nitrogen < 20): ?>

                <?php
                $recommendationCount++;
                $criticalCount++;
                ?>

                <div class="recommendation-item critical">

                    <h4>3. Nitrogen Level is Low</h4>

                    <p>
                        The nitrogen reading is
                        <strong><?= number_format($nitrogen, 1) ?> mg/kg</strong>.
                        Low nitrogen may affect plant growth and leaf
                        development.
                    </p>

                    <strong>Recommended actions:</strong>

                    <ul>
                        <li>
                            Consider applying a nitrogen fertilizer such as
                            urea or ammonium sulfate.
                        </li>

                        <li>
                            Follow the recommended fertilizer rate for the
                            crop and field condition.
                        </li>

                        <li>
                            Avoid applying excessive nitrogen because
                            over-fertilization can damage crops and cause
                            nutrient losses.
                        </li>

                        <li>
                            If the soil is very dry, manage soil moisture
                            before applying fertilizer.
                        </li>

                        <li>
                            Monitor crop color, growth, and future nitrogen
                            readings after application.
                        </li>
                    </ul>

                </div>

            <?php else: ?>

                <?php
                if ($nitrogen !== null) {
                    $goodCount++;
                }
                ?>

                <div class="recommendation-item good">

                    <h4>3. Nitrogen Level is Adequate</h4>

                    <p>
                        The nitrogen reading is
                        <strong><?= number_format($nitrogen, 1) ?> mg/kg</strong>.
                        No additional nitrogen correction is indicated by
                        the current sensor reading.
                    </p>

                    <ul>
                        <li>
                            Maintain the current fertilizer management.
                        </li>

                        <li>
                            Avoid unnecessary nitrogen application.
                        </li>

                        <li>
                            Continue monitoring nutrient levels as the crop
                            develops.
                        </li>
                    </ul>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 PHOSPHORUS
            ================================================== -->

            <?php if ($phosphorus !== null && $phosphorus < 10): ?>

                <?php
                $recommendationCount++;
                $warningCount++;
                ?>

                <div class="recommendation-item warning">

                    <h4>4. Phosphorus Level is Low</h4>

                    <p>
                        The phosphorus reading is
                        <strong><?= number_format($phosphorus, 1) ?> mg/kg</strong>.
                        Low phosphorus can affect root development and
                        overall plant growth.
                    </p>

                    <strong>Recommended actions:</strong>

                    <ul>
                        <li>
                            Consider an appropriate phosphate fertilizer
                            based on soil-test and crop recommendations.
                        </li>

                        <li>
                            Apply fertilizer according to the recommended
                            rate instead of applying excessive amounts.
                        </li>

                        <li>
                            Maintain suitable soil moisture to support
                            nutrient availability.
                        </li>

                        <li>
                            Monitor phosphorus levels after fertilizer
                            application.
                        </li>

                        <li>
                            Avoid repeated fertilizer application without
                            checking the soil condition.
                        </li>
                    </ul>

                </div>

            <?php else: ?>

                <?php
                if ($phosphorus !== null) {
                    $goodCount++;
                }
                ?>

                <div class="recommendation-item good">

                    <h4>4. Phosphorus Level is Adequate</h4>

                    <p>
                        The phosphorus reading is
                        <strong><?= number_format($phosphorus, 1) ?> mg/kg</strong>.
                        No immediate phosphorus correction is indicated.
                    </p>

                    <ul>
                        <li>
                            Continue the current nutrient management.
                        </li>

                        <li>
                            Avoid unnecessary phosphate application.
                        </li>

                        <li>
                            Continue monitoring phosphorus during the crop
                            growth period.
                        </li>
                    </ul>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 POTASSIUM
            ================================================== -->

            <?php if ($potassium !== null && $potassium < 15): ?>

                <?php
                $recommendationCount++;
                $warningCount++;
                ?>

                <div class="recommendation-item warning">

                    <h4>5. Potassium Level is Low</h4>

                    <p>
                        The potassium reading is
                        <strong><?= number_format($potassium, 1) ?> mg/kg</strong>.
                        Low potassium may reduce plant resistance and affect
                        overall crop development.
                    </p>

                    <strong>Recommended actions:</strong>

                    <ul>
                        <li>
                            Consider a potassium fertilizer such as
                            muriate of potash when appropriate for the crop.
                        </li>

                        <li>
                            Follow the recommended fertilizer application
                            rate.
                        </li>

                        <li>
                            Avoid excessive potassium application because
                            nutrient imbalance can occur.
                        </li>

                        <li>
                            Maintain proper soil moisture to support nutrient
                            availability.
                        </li>

                        <li>
                            Monitor the next potassium reading after
                            treatment.
                        </li>
                    </ul>

                </div>

            <?php else: ?>

                <?php
                if ($potassium !== null) {
                    $goodCount++;
                }
                ?>

                <div class="recommendation-item good">

                    <h4>5. Potassium Level is Adequate</h4>

                    <p>
                        The potassium reading is
                        <strong><?= number_format($potassium, 1) ?> mg/kg</strong>.
                        No immediate potassium correction is indicated.
                    </p>

                    <ul>
                        <li>
                            Maintain the current fertilizer management.
                        </li>

                        <li>
                            Avoid unnecessary potassium application.
                        </li>

                        <li>
                            Continue monitoring nutrient levels.
                        </li>
                    </ul>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 TEMPERATURE
            ================================================== -->

            <?php if ($temperature !== null && $temperature > 35): ?>

                <?php
                $recommendationCount++;
                $warningCount++;
                ?>

                <div class="recommendation-item warning">

                    <h4>6. Soil Temperature is High</h4>

                    <p>
                        The current temperature is
                        <strong><?= number_format($temperature, 1) ?> °C</strong>.
                        High temperatures can increase moisture loss from
                        the soil.
                    </p>

                    <strong>Recommended actions:</strong>

                    <ul>
                        <li>
                            Monitor soil moisture more frequently.
                        </li>

                        <li>
                            Prevent the soil from becoming excessively dry.
                        </li>

                        <li>
                            Adjust irrigation when necessary based on
                            moisture readings.
                        </li>

                        <li>
                            Check the field during the hottest parts of
                            the day when conditions are extreme.
                        </li>

                        <li>
                            Consider suitable soil-cover or crop-management
                            practices that help reduce excessive moisture
                            loss.
                        </li>
                    </ul>

                </div>

            <?php elseif ($temperature !== null && $temperature >= 32.5): ?>

                <?php
                $recommendationCount++;
                $warningCount++;
                ?>

                <div class="recommendation-item warning">

                    <h4>6. Soil Temperature is Warm</h4>

                    <p>
                        The current temperature is
                        <strong><?= number_format($temperature, 1) ?> °C</strong>.
                        Continue monitoring moisture because warmer
                        conditions may increase water loss.
                    </p>

                    <ul>
                        <li>
                            Monitor soil moisture more frequently.
                        </li>

                        <li>
                            Maintain appropriate irrigation.
                        </li>

                        <li>
                            Avoid allowing the soil to become excessively
                            dry.
                        </li>
                    </ul>

                </div>

            <?php else: ?>

                <?php
                if ($temperature !== null) {
                    $goodCount++;
                }
                ?>

                <div class="recommendation-item good">

                    <h4>6. Soil Temperature is Within the Normal Range</h4>

                    <p>
                        The current temperature is
                        <strong><?= number_format($temperature, 1) ?> °C</strong>.
                        No temperature-related corrective action is
                        indicated by the current reading.
                    </p>

                    <ul>
                        <li>
                            Continue normal field monitoring.
                        </li>

                        <li>
                            Continue checking temperature together with
                            soil moisture.
                        </li>
                    </ul>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 COMBINED RECOMMENDATIONS
            ================================================== -->

            <?php if (
                $moisture !== null &&
                $moisture < 30 &&
                $nitrogen !== null &&
                $nitrogen < 20
            ): ?>

                <?php
                $recommendationCount++;
                ?>

                <div class="recommendation-item priority">

                    <h4>Priority Action: Low Moisture and Low Nitrogen</h4>

                    <p>
                        The field currently has both low soil moisture and
                        low nitrogen.
                    </p>

                    <ul>
                        <li>
                            Restore suitable soil moisture gradually.
                        </li>

                        <li>
                            Avoid immediately applying a large amount of
                            fertilizer to extremely dry soil.
                        </li>

                        <li>
                            Once appropriate moisture is restored, follow
                            the recommended nitrogen fertilizer program.
                        </li>

                        <li>
                            Avoid excessive irrigation after fertilizer
                            application.
                        </li>

                        <li>
                            Recheck moisture and nitrogen readings after
                            the field has been managed.
                        </li>
                    </ul>

                </div>

            <?php endif; ?>


            <?php if (
                $moisture !== null &&
                $moisture > 60 &&
                $nitrogen !== null &&
                $nitrogen < 20
            ): ?>

                <?php
                $recommendationCount++;
                ?>

                <div class="recommendation-item priority">

                    <h4>Priority Action: High Moisture and Low Nitrogen</h4>

                    <p>
                        The field has high moisture while the nitrogen
                        reading remains low.
                    </p>

                    <ul>
                        <li>
                            Check drainage before adding more water.
                        </li>

                        <li>
                            Temporarily reduce irrigation.
                        </li>

                        <li>
                            Do not compensate for low nitrogen by applying
                            excessive fertilizer.
                        </li>

                        <li>
                            Once soil conditions are suitable, follow the
                            recommended nitrogen fertilizer program.
                        </li>

                        <li>
                            Continue monitoring both moisture and nitrogen.
                        </li>
                    </ul>

                </div>

            <?php endif; ?>


            <?php if (
                $ph !== null &&
                $ph < 5.0 &&
                $phosphorus !== null &&
                $phosphorus < 10
            ): ?>

                <?php
                $recommendationCount++;
                ?>

                <div class="recommendation-item priority">

                    <h4>Priority Action: Acidic Soil and Low Phosphorus</h4>

                    <p>
                        The soil is acidic and phosphorus is also below
                        the monitoring threshold.
                    </p>

                    <ul>
                        <li>
                            Address soil acidity according to soil-test
                            recommendations.
                        </li>

                        <li>
                            Consider agricultural lime or dolomite at the
                            recommended rate.
                        </li>

                        <li>
                            Avoid excessive lime application.
                        </li>

                        <li>
                            Follow the appropriate phosphorus fertilizer
                            recommendation for the crop.
                        </li>

                        <li>
                            Recheck soil pH and phosphorus after treatment.
                        </li>
                    </ul>

                </div>

            <?php endif; ?>


            <?php if (
                $ph !== null &&
                $ph > 7.5 &&
                $phosphorus !== null &&
                $phosphorus < 10
            ): ?>

                <?php
                $recommendationCount++;
                ?>

                <div class="recommendation-item priority">

                    <h4>Priority Action: Alkaline Soil and Low Phosphorus</h4>

                    <p>
                        The soil pH is high while phosphorus is below the
                        monitoring threshold.
                    </p>

                    <ul>
                        <li>
                            Avoid adding materials that further increase
                            soil alkalinity.
                        </li>

                        <li>
                            Follow soil-test recommendations for correcting
                            pH.
                        </li>

                        <li>
                            Consider suitable organic matter management
                            where appropriate.
                        </li>

                        <li>
                            Follow the recommended phosphorus management
                            plan.
                        </li>

                        <li>
                            Continue monitoring pH and phosphorus.
                        </li>
                    </ul>

                </div>

            <?php endif; ?>


            <?php if (
                $temperature !== null &&
                $temperature > 35 &&
                $moisture !== null &&
                $moisture < 30
            ): ?>

                <?php
                $recommendationCount++;
                ?>

                <div class="recommendation-item priority">

                    <h4>Priority Action: High Temperature and Low Moisture</h4>

                    <p>
                        High temperature combined with low moisture may
                        increase water stress in the field.
                    </p>

                    <ul>
                        <li>
                            Monitor soil moisture more frequently.
                        </li>

                        <li>
                            Irrigate gradually when needed.
                        </li>

                        <li>
                            Avoid allowing the soil to remain excessively
                            dry for long periods.
                        </li>

                        <li>
                            Check irrigation coverage across the field.
                        </li>

                        <li>
                            Continue monitoring temperature and moisture
                            during hot periods.
                        </li>
                    </ul>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 GENERAL MANAGEMENT
            ================================================== -->

            <div class="recommendation-item general">

                <h4>General Farm Management</h4>

                <ul>
                    <li>
                        Monitor the dashboard regularly instead of relying
                        on a single sensor reading.
                    </li>

                    <li>
                        Compare new readings with previous readings to
                        identify improving or worsening soil conditions.
                    </li>

                    <li>
                        Avoid applying large amounts of fertilizer based
                        only on one sensor reading.
                    </li>

                    <li>
                        Use soil-test results and recommended crop-specific
                        fertilizer rates when making fertilizer decisions.
                    </li>

                    <li>
                        Maintain proper irrigation and drainage management.
                    </li>

                    <li>
                        Record major irrigation and fertilizer applications
                        so changes in sensor readings can be compared with
                        field management activities.
                    </li>

                    <li>
                        If readings remain abnormal despite corrective
                        actions, consult a qualified agricultural technician
                        or soil specialist.
                    </li>
                </ul>

            </div>


            <!-- =================================================
                 OVERALL STATUS
            ================================================== -->

            <?php if ($recommendationCount === 0): ?>

                <div class="recommendation-item good">

                    <h4>Overall Soil Condition</h4>

                    <p>
                        No immediate corrective action is indicated by the
                        current sensor readings.
                    </p>

                    <ul>
                        <li>
                            Continue the current irrigation management.
                        </li>

                        <li>
                            Maintain the current fertilizer program.
                        </li>

                        <li>
                            Continue regular soil monitoring.
                        </li>

                        <li>
                            Check future readings for changes in moisture,
                            pH, nitrogen, phosphorus, potassium, and
                            temperature.
                        </li>
                    </ul>

                </div>

            <?php else: ?>

                <div class="recommendation-summary">

                    <h4>Recommendation Summary</h4>

                    <p>
                        The system identified
                        <strong><?= $recommendationCount ?></strong>
                        condition(s) that should be monitored or managed.
                    </p>

                    <?php if ($criticalCount > 0): ?>

                        <p>
                            <strong>Priority:</strong>
                            <?= $criticalCount ?>
                            condition(s) require closer attention.
                        </p>

                    <?php endif; ?>

                    <?php if ($warningCount > 0): ?>

                        <p>
                            <strong>Monitoring:</strong>
                            <?= $warningCount ?>
                            condition(s) are outside the preferred
                            monitoring range.
                        </p>

                    <?php endif; ?>

                    <p>
                        Continue monitoring the next sensor readings after
                        making any appropriate field-management changes.
                    </p>

                </div>

            <?php endif; ?>

        </div>


    <?php elseif ($role === 'admin'): ?>

        <!-- =====================================================
             ADMIN RECOMMENDATIONS
        ====================================================== -->

        <div class="recommendation-section">

            <h3>Cooperative Administrative Recommendations</h3>

            <div class="recommendation-item">

                <h4>Monitor Farmer Soil Conditions</h4>

                <ul>
                    <li>
                        Review soil conditions regularly across registered
                        farmer nodes.
                    </li>

                    <li>
                        Identify farmers with repeated low moisture,
                        abnormal pH, or nutrient deficiencies.
                    </li>

                    <li>
                        Monitor repeated critical readings instead of
                        relying on a single reading.
                    </li>

                    <li>
                        Coordinate with farmers when persistent soil
                        problems are detected.
                    </li>

                    <li>
                        Encourage farmers to verify fertilizer decisions
                        with soil-test results and agricultural guidance.
                    </li>
                </ul>

            </div>

        </div>

    <?php endif; ?>

<?php endif; ?>

</div>


<style>

.recommendation-section {
    margin-top: 20px;
}

.recommendation-intro {
    margin-bottom: 20px;
    color: #555;
}

.recommendation-readings {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 12px;
    margin-top: 15px;
}

.reading-card {
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 15px;
    background: #fff;
}

.reading-card strong {
    display: block;
    margin-bottom: 8px;
}

.reading-card span {
    display: block;
    font-size: 20px;
    font-weight: 600;
    margin-bottom: 5px;
}

.reading-card small {
    color: #666;
}

.recommendation-item {
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 18px;
    margin: 15px 0;
    background: #fff;
}

.recommendation-item h4 {
    margin-top: 0;
    margin-bottom: 10px;
}

.recommendation-item ul {
    margin-top: 10px;
    padding-left: 22px;
}

.recommendation-item li {
    margin-bottom: 8px;
    line-height: 1.5;
}

.recommendation-item.critical {
    border-left: 5px solid #dc3545;
}

.recommendation-item.warning {
    border-left: 5px solid #f0ad4e;
}

.recommendation-item.good {
    border-left: 5px solid #28a745;
}

.recommendation-item.priority {
    border-left: 5px solid #6f42c1;
}

.recommendation-item.general {
    border-left: 5px solid #6c757d;
}

.recommendation-summary {
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 18px;
    margin-top: 20px;
    background: #f8f9fa;
}

.recommendation-empty {
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    margin-top: 20px;
    background: #fff;
}

</style>