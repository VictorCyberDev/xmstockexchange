<?php
// Site defaults
if (!isset($title)) {
    $title = "Your Company Name";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">

    <title><?php echo htmlspecialchars($title); ?></title>

    <!-- =====================
         CORE CSS FILES
    ====================== -->

    <!-- Bootstrap -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">

    <!-- Font Awesome (icons used heavily in index.php) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Animate.css (used with WOW.js) -->
    <link rel="stylesheet" href="css/animate.css">
    
    <link rel="stylesheet" href="assets/css/app.css">
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">


    <!-- Main Styles -->
    <link rel="stylesheet" href="css/style.css">

    <!-- Responsive (optional but recommended) -->
    <link rel="stylesheet" href="css/responsive.css">

    <!-- =====================
         GOOGLE FONTS (SAFE)
    ====================== -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

</head>
<body>
