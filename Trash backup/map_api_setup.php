<?php
/**
 * Google Maps Locator + REST API + Inline Map Script
 * - Markers always visible on the map (even before search)
 * - #search_result hidden by default; only shown (and filled) after a search (or query param)
 * - Uses "shop_name" field as title (fallback to post title)
 */

/* === ACF Google API Key === */
function my_acf_init() {
    acf_update_setting('google_api_key', 'AIzaSyAOHCVaFd1tXMX04Qc87sGkc4ItrnWe8Q8');
} 
add_action('acf/init', 'my_acf_init');

/* === Enqueue Google Maps JS === */
function enqueue_google_maps_script() {
    wp_enqueue_script('google-maps', 'https://maps.googleapis.com/maps/api/js?key=AIzaSyAOHCVaFd1tXMX04Qc87sGkc4ItrnWe8Q8', array(), null, true);
}
add_action('wp_enqueue_scripts', 'enqueue_google_maps_script');

add_action( 'wp_head', function(){
    if(is_page( 18 )) all_pin_data();
});
function all_pin_data(){
    $args = array(
        'post_type'      => 'store',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    );
    $query = new WP_Query($args);

    $mapAddresses = [];

    if ($query->have_posts()){
        while ($query->have_posts())  {
            $query->the_post();
            $mapAddress = get_post_meta(get_the_ID(), 'store_address', true);
            if(!is_array($mapAddress)) $mapAddress = [];
            $mapAddress["title"] = get_the_title();
            $mapAddresses[] = $mapAddress;
        }
        wp_reset_postdata();
    }
    ?>
        <script>
            const storeAddress = <?php echo json_encode($mapAddresses) ?>
        </script>
    <?php
    // return $mapAddresses;
}


/* === Output styles + external + inline scripts at the end of <body> === */
add_action('wp_footer', function () { 
    if(!is_page( 18 )) return;
    ?>
<style>
  /* Hide results by default; only show after a search */
  #search_result { display: none; }
  /* Make sure the map is visibly sized */
  #map { width: 100%; height: 420px; }
</style>

<!-- <script src="https://unpkg.com/@googlemaps/markerclusterer/dist/index.min.js"></script> -->
<script>
    const mapContainer = document.querySelector("#map");

    let map, openInfoWindow;

    // Build the map on load with default center and ALL markers (no list yet)
    window.addEventListener("DOMContentLoaded", initMap);
    // initMap();
     function initMap(){
        map = new google.maps.Map(mapContainer, {
            center: { lat: 32.0855937504297, lng: 34.79248881835856}, 
            zoom: 20,
            // styles: [{ elementType: "all", stylers: [{ saturation: "-100" }]}]
        });
        try{
            storeAddress.forEach((point) => {
                addMarker(point)
            });
        }catch(err){
            console.log("error");
        }
    }

    // Adds a marker (and info window + marker click) — does NOT add a list card
    function addMarker(eachMarker){
        if(!(eachMarker?.title && eachMarker?.lat && eachMarker?.lng && eachMarker?.address)) return;

        const titleText = eachMarker?.title || "";

        const infoWin = new google.maps.InfoWindow({
            content: `
                <div class="info_window">
                    <div class="title">${titleText}</div>
                    <a href="https://www.google.com/maps/dir/?api=1&destination=${ eachMarker?.address || "#"}" target="blank" class="sg_page_url">Bekijk locatie ></a>
                </div>
            `,
        });

        const marker = new google.maps.Marker({
            position: { lat: eachMarker?.lat, lng: eachMarker?.lng },
            title: titleText,
            icon: {
                url: "https://office3daa9d2f44-jumvh.wpcomstaging.com/wp-content/uploads/2025/08/f29de8_1115fb2bb33b43b2a308909be286d2eamv2.png",
                scaledSize: new google.maps.Size(25, 25),
            },
            map
        });

        marker.addListener("click", function(){
            if(openInfoWindow) openInfoWindow.close();
            openInfoWindow = infoWin;
            infoWin.open(map, marker);
        });

        // Keep a pointer so clicking a list item can open this marker
        marker.__infoWindow = infoWin;
        marker.__data = eachMarker;
    }

</script>
<?php });


