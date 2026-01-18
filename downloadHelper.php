<?PHP
header('Content-Type: application/javascript');
session_start();
?>
function populateLink() {
    var linkTD = document.getElementById("tdDownloadLink");
    linkTD.innerHTML = "<a href=\"javascript:getLink('" + linkTD.getAttribute("data-encoded-uri") + "', " + linkTD.getAttribute("data-app-id") + ")\">Direct Link</a>";
    var linkAltTD = document.getElementById("tdAltDownloadLink");
    if (linkAltTD)
        linkAltTD.innerHTML = "<a href=\"javascript:getLink('" + linkAltTD.getAttribute("data-encoded-uri") + "', " + linkAltTD.getAttribute("data-app-id") + ")\">Direct Link</a>";
}

function getLink(encodedLink, appId)
{
    countAppDownloads(appId);
    //Use proxy to serve HTTP files over HTTPS
    //The encoded link includes a session salt for validation
    var pageParts = window.location.pathname.split("/");
    var lastPage = pageParts[pageParts.length-1];
    var urlParts = window.location.href.split(lastPage);
    var proxyURL = urlParts[0] + 'downloadProxy.php?url=' + encodeURIComponent(encodedLink) + '&appid=' + appId;
    window.open(proxyURL);
}

function countAppDownloads(appId) {
    try {
        var pageParts = window.location.pathname.split("/");
        var lastPage = pageParts[pageParts.length-1];
        var urlParts = window.location.href.split(lastPage);
        var url = urlParts[0] + 'WebService/countAppDownload.php?appid=' + appId + "&source=" + encodeURIComponent(navigator.userAgent);
        
        var xhr = new XMLHttpRequest();
        xhr.open('GET', url);
        xhr.send();
    } catch (ex) {
        console.log("Error counting app download: " + ex);
    }
}