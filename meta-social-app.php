<meta name="description" content="webOS App Museum is the definitive historical archive of legacy Palm/HP webOS mobile apps and games!" />
<link rel="canonical" href="<?php echo htmlspecialchars($share_url, ENT_QUOTES); ?>" />
<meta property="og:locale" content="en_US" />
<meta property="og:type" content="website" />
<meta property="og:title" content="<?php echo htmlspecialchars($found_app["title"], ENT_QUOTES); ?> on webOS App Museum" />
<meta property="og:description" content="<?php echo $meta_desc; /* already htmlspecialchars'd upstream via $app_detail["description"] */ ?>" />
<meta property="og:url" content="<?php echo htmlspecialchars($share_url, ENT_QUOTES); ?>" />
<meta property="og:site_name" content="webOS App Museum" />
<meta property="article:published_time" content="<?php echo htmlspecialchars($app_detail["lastModifiedTime"], ENT_QUOTES); ?>" />
<meta property="article:modified_time" content="<?php echo htmlspecialchars($app_detail["lastModifiedTime"], ENT_QUOTES); ?>" />
<meta property="og:image" content="<?php echo htmlspecialchars($use_icon, ENT_QUOTES); ?>" />
<meta property="og:image:width" content="256" />
<meta property="og:image:height" content="256" />
<meta property="og:image:type" content="image/png" />
<meta name="author" content="webOS Archive" />
<meta name="twitter:card" content="summary" />
<meta name="twitter:title" content="<?php echo htmlspecialchars($found_app["title"], ENT_QUOTES); ?> on webOS App Museum" />
<meta name="twitter:description" content="<?php echo $meta_desc; /* already htmlspecialchars'd upstream via $app_detail["description"] */ ?>" />
<meta name="twitter:image" content="<?php echo htmlspecialchars($use_icon, ENT_QUOTES); ?>" />