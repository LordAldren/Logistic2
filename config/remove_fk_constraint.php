<?php
/**
 * Script to remove foreign key constraints from trips and reservations tables
 * This allows using API vehicle IDs that don't exist in local vehicles table
 * 
 * Run this once by visiting: http://localhost/finallog/config/remove_fk_constraint.php
 */

require_once 'db_connect.php';

echo "<h1>Foreign Key Constraint Removal Tool</h1>";
echo "<hr>";

// =====================================================
// 1. REMOVE FK FROM TRIPS TABLE
// =====================================================
echo "<h2>1. Trips Table</h2>";

$result = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'trips' 
    AND COLUMN_NAME = 'vehicle_id' 
    AND REFERENCED_TABLE_NAME = 'vehicles'");

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $constraint_name = $row['CONSTRAINT_NAME'];
    
    echo "<p>Found constraint: <strong>$constraint_name</strong></p>";
    
    $drop_sql = "ALTER TABLE trips DROP FOREIGN KEY `$constraint_name`";
    
    if ($conn->query($drop_sql)) {
        echo "<p style='color: green; font-weight: bold;'>✅ SUCCESS! Foreign key constraint '$constraint_name' has been removed from trips table.</p>";
    } else {
        echo "<p style='color: red;'>❌ Error removing constraint: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color: orange;'>⚠️ No foreign key constraint found on trips.vehicle_id → vehicles.id</p>";
    echo "<p>The constraint may have already been removed.</p>";
}

echo "<hr>";

// =====================================================
// 2. REMOVE FK FROM RESERVATIONS TABLE
// =====================================================
echo "<h2>2. Reservations Table</h2>";

$result2 = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'reservations' 
    AND COLUMN_NAME = 'vehicle_id' 
    AND REFERENCED_TABLE_NAME = 'vehicles'");

if ($result2 && $result2->num_rows > 0) {
    $row2 = $result2->fetch_assoc();
    $constraint_name2 = $row2['CONSTRAINT_NAME'];
    
    echo "<p>Found constraint: <strong>$constraint_name2</strong></p>";
    
    $drop_sql2 = "ALTER TABLE reservations DROP FOREIGN KEY `$constraint_name2`";
    
    if ($conn->query($drop_sql2)) {
        echo "<p style='color: green; font-weight: bold;'>✅ SUCCESS! Foreign key constraint '$constraint_name2' has been removed from reservations table.</p>";
    } else {
        echo "<p style='color: red;'>❌ Error removing constraint: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color: orange;'>⚠️ No foreign key constraint found on reservations.vehicle_id → vehicles.id</p>";
    echo "<p>The constraint may have already been removed.</p>";
}

echo "<hr>";

// =====================================================
// SUMMARY & NAVIGATION
// =====================================================
echo "<h2>Summary</h2>";
echo "<p>You can now use API vehicle IDs when creating reservations and trips.</p>";
echo "<p><strong>Navigation:</strong></p>";
echo "<ul>";
echo "<li><a href='../modules/vrds/reservation_booking.php'>Go to Reservation Booking</a></li>";
echo "<li><a href='../modules/vrds/dispatch_control.php'>Go to Dispatch Control</a></li>";
echo "</ul>";

$conn->close();
?>
