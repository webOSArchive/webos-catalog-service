<!DOCTYPE html>
<html lang="en">
<?php
//This file is only used for advertising on a hosting webserver

//Figure out what protocol the client wanted
if(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        $PROTOCOL = "https";
} else {
        $PROTOCOL = "http";
}

//App Details
$title = "webOS App Museum";
$subtitle = " | webOS Archive";
$description = "The App Museum is a community project to archive, restore and provide access to the historical catalog of apps for Palm/HP's defunct mobile platform, webOS.";
$github = "https://github.com/webOSArchive/webos-catalog-service";
$homeLink = $PROTOCOL."://appcatalog.webosarchive.org";
$icon = $homeLink."/assets/icon.png";

$config = include('WebService/config.php');

//Get the app info
if ($PROTOCOL == "https://")
  $download_path = $PROTOCOL . $config["package_host_secure"] . "/";
else
  $download_path = $PROTOCOL . $config["package_host"] . "/";
$content = file_get_contents(__DIR__ . '/0.json');
$outputObj = json_decode($content, true);
if (strpos($outputObj["filename"], "://") === false) {
  $use_uri = $download_path . $outputObj["filename"];
} else {
  $use_uri = $outputObj["filename"];
}
?>
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">

  <meta name="description" content="<?php echo $description; ?>">
  <meta name="keywords" content="webos, firefoxos, pwa, rss">
  <meta name="author" content="webOS Archive">
  <meta property="og:title" content="<?php echo $title; ?>">
  <meta property="og:description" content="<?php echo $description; ?>">
  <meta property="og:image" content="https://<?php echo $_SERVER['SERVER_NAME'] ?>/hero.png">

  <meta name="twitter:card" content="app">
  <meta name="twitter:site" content="@webOSArchive">
  <meta name="twitter:title" content="<?php echo $title; ?>">
  <meta name="twitter:description" content="<?php echo $description; ?>">

  <title><?php echo $title . $subtitle; ?></title>

  <link id="favicon" rel="icon" type="image/png" sizes="64x64" href="<?php echo $icon;?>">
  <link rel="alternate" type="application/rss+xml" title="webOS App Museum - What's New" href="feed.php">
  <link href="<?php echo $PROTOCOL . "://www.webosarchive.org/app-template/"?>web.css" rel="stylesheet" type="text/css" >
  <style>
    a { text-decoration: none; }
    a:hover { text-decoration: underline; }
    #hero { padding-top:60px }
    @media all and (max-width: 767px) {
        #hero { padding-top: 0px !important; }
    }
    small { font-size: 15px; }
    /* Vertically center the page content on tall screens. A 100%-height
       table (rather than flexbox or vh units) so 2011-era webOS WebKit
       gets the same result; the table simply grows past the viewport on
       small screens, so nothing is clipped and no extra scrollbar appears. */
    html, body { height: 100%; }
    #page { width: 100%; height: 100%; border: 0; border-collapse: collapse; }
    #page td { border: 0; padding: 0; }
    #page-menu { height: 1px; }
    #page-body { vertical-align: middle; }
    #row { margin-left: auto; margin-right: auto; }
  </style>
  <script>
    function setOS(OSName) {
      document.getElementById("show-enyo").style.display = "none";
      document.getElementById("show-mojo").style.display = "none";
      document.getElementById("show-luneos").style.display = "none";
      document.getElementById("show-" + OSName).style.display ="block";
      document.getElementById("explain-enyo").style.display = "none";
      document.getElementById("explain-mojo").style.display = "none";
      document.getElementById("explain-luneos").style.display = "none";
      document.getElementById("explain-" + OSName).style.display ="block";
      if (OSName == "luneos")
          document.getElementById("show-preware").style.display ="none";
      else
          document.getElementById("show-preware").style.display ="block";
    }
  </script>
</head>
<body>
  <table id="page" width="100%" height="100%" border="0" cellspacing="0" cellpadding="0">
  <tr><td id="page-menu">
<?php

$docRoot = "./";
echo file_get_contents("https://www.webosarchive.org/menu.php?docRoot=" . $docRoot . "&protocol=" . $PROTOCOL);
?>
  </td></tr>
  <tr><td id="page-body" align="center" valign="middle">
  <div id="row">
    <div id="content" align="left">
      <p style="font-size:30px;font-weight:600;"><img src="<?php echo $icon;?>" width="60" height="60" alt="" style="vertical-align:top;height:60px;width:60px;"/> <?php echo $title; ?></p>
      <p><?php echo $description; ?></p>
      <p>The recovered catalog is stored on the <a href="http://archive.org/details/@webos_archive">Internet Archive</a>, and can be browsed a number of ways... </p>
      <div style="font-size:0.98em">

        <a class="download-link" href="showMuseum.php">
           <img src="assets/browser-icon.png" style="vertical-align:middle" alt="Browse Online" title="Browse Online" width="48" height="48"/> Browse Online </a>
           | <a class="download-link" href="http://archive.org/details/webosappcatalog"> Full Archive</a>
           | <a class="download-link" href="feed.php" title="RSS feed of new apps and updates"><img src="assets/rss.png" style="vertical-align:middle;height:16px;width:16px;"> RSS Feed</a>
        <br><br>
        <div style="font-weight:bold; margin-bottom:8px;">Install on a Device:
        <select id="selectOS" onchange="setOS(document.getElementById('selectOS').value);">
          <option value="enyo" selected>HP webOS Tablet</option>
          <option value="mojo">Palm/HP webOS Phone</option>
          <option value="luneos">Other LuneOS Device</option>
        </select>
        </div>
        <p style="font-size:smaller" id="explain-enyo">HP TouchPad, TouchPad 4G or TouchPad Go on webOS 3.0.x.<br>Note: <a href="http://www.webosarchive.org/pivot/2026/09/08/webos-3.1.0-community-edition-is-here/">webOS 3.1.0</a> has everything pre-installed.</p>
        <p style="font-size:smaller; display:none;" id="explain-mojo">Palm Pre, Pre Plus, Pre2, Pixi; HP Veer or Pre3.</p>
        <p style="font-size:smaller; display:none;" id="explain-luneos">Modern devices running <a href="http://www.webosarchive.org/pivot/author/webosports/">LuneOS</a> or the <a href="https://sdk.webosarchive.org">Enyo library</a> in WebKit.</p>

        <span id="show-preware"><a class="download-link" href="http://docs.webosarchive.org/#step-5"><img src="assets/preware-icon.png" style="vertical-align:middle;height:48px;width:48px;" alt="Preware" title="Preware"> 1) Install Preware | Requires WOSQI + Java</a><br/></span>
        <span id="show-enyo" style="display:block"><a class="download-link" href="AppPackages/com.palm.app.enyo-findapps_6.0.2900_all.ipk"><img src="assets/hp-appcatalog.png" style="vertical-align:middle;height:48px;width:48px;" alt="HP App Catalog for TouchPad" title="HP App Catalog for TouchPad"> 2) Restored App Catalog (Enyo)</a><br></span>
        <span id="show-mojo" style="display:none"><a class="download-link" href="AppPackages/com.palm.app.findapps_3.0.23300_all.ipk"><img src="assets/palm-appcatalog.png" style="vertical-align:middle;height:48px;width:48px;" alt="HP App Catalog" title="HP App Catalog for Phones"> 2) Restored App Catalog (Mojo)</a><br></span>
        <span id="show-luneos" style="display:none"><a class="download-link" href="<?php echo $use_uri?>"><img src="assets/icon.png" style="vertical-align:middle" alt="App Museum for LuneOS" title="App Museum for LuneOS" width="48" height="48"/> App Museum (WebKit/LuneOS)</a></span>
        <p style="font-size:smaller"><a href="http://docs.webosarchive.org">Need More Help?</a></p>
      </div>
    </div>
    <div id="hero">
      <a href="showMuseum.php"><img src="hero.png" width="480" border="0" style="border:0px" alt="<?php echo $title ?>" /></a>
      <p style="font-size:1.1em">Catalog metadata is available on <?php echo "<a href='" . $github . "'>GitHub</a>"?> | <a href="http://appcatalog.webosarchive.org/WebService/reports/">View Stats</a></p>
      <p style="font-size:0.98em"><i>Many items are still missing! If you have an old device or personal archive, check the <a href="wanted.txt">wanted</a> <a href="wanted.csv">list</a>, or run the <a href="http://appcatalog.webosarchive.org/app/webOSAppScanner">App Scanner</a> on your device, and <a href="mailto:webosarchive@gmail.com">email us</a> if you have any matches!</i></p>
    </div>
  </div>
  <div id="footer">
    &copy; webOSArchive <?php echo date("Y"); ?>
    <div id="footer-links">
    <a href="<?php echo $PROTOCOL . "://www.webosarchive.org/privacy.html"?>">Privacy Policy</a>
    </div>
  </div>
  </td></tr></table>
</body>
</html>
