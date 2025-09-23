<?php

/**
 * Google Maps Locator + REST API + Inline Map Script
 * - Markers always visible on the map (even before search)
 * - #search_result hidden by default; only shown (and filled) after a search (or query param)
 * - Uses "shop_name" field as title (fallback to post title)
 */

/* === ACF Google API Key === */
function my_acf_init() {
    acf_update_setting('google_api_key', 'AIzaSyCljsKKpC5dCqsCAUovmBc6kxqNrf7VkY0');
}
add_action('acf/init', 'my_acf_init');

/* === Enqueue Google Maps JS === */
function enqueue_google_maps_script() {
    wp_enqueue_script('google-maps', 'https://maps.googleapis.com/maps/api/js?key=AIzaSyCljsKKpC5dCqsCAUovmBc6kxqNrf7VkY0', array(), null, true);
}
add_action('wp_enqueue_scripts', 'enqueue_google_maps_script');

/* === Output styles + external + inline scripts at the end of <body> === */
add_action('wp_footer', function () { ?>
<style>
  /* Hide results by default; only show after a search */
  #search_result { display: none; }
  /* Make sure the map is visibly sized */
  #map { width: 100%; height: 420px; }
</style>

<script src="https://unpkg.com/@googlemaps/markerclusterer/dist/index.min.js"></script>
<script>
    const searchFild = document.querySelector("#search_fild");
    const searchButton = document.querySelector("#search_button");
    const searchResultCon = document.querySelector("#search_result");
    const mapContainer = document.querySelector("#map");

    let map,
        locationApi = `/wp-json/map/v1/locations`,
        searchRaidus = 20,
        position = { lat: 52.103359711221245, lng: 5.2462851862407405 },
        markers = [],
        openInfoWindow,
        clusterer;

    const urlParams = new URLSearchParams(window.location.search);
    let searchVal = urlParams.get('mapstorelocation') || "";

    // Determine whether to show list (only after a search or param)
    let didSearch = !!searchVal;

    if (searchVal && searchFild) {
        searchFild.value = searchVal;
    }

    if (searchButton) {
        searchButton.addEventListener("click", function(){
            searchVal = (searchFild && searchFild.value) ? searchFild.value : "";
            didSearch = !!searchVal;
            if (searchVal) initMap();   // run a search
        });
    }

    // Build the map on load with default center and ALL markers (no list yet)
    window.addEventListener("DOMContentLoaded", initMap);

    async function initMap(){
        const { Map, InfoWindow } = await google.maps.importLibrary("maps");
        const { MarkerClusterer } = markerClusterer;

        let searchPosi = null;
        let apiUrl = locationApi; // default: all locations

        if (searchVal) {
            try{
                searchPosi = await getCoordinates(searchVal);
                if(searchPosi.lat === 0 || searchPosi.lng === 0) throw new Error();
            }catch(err){
                searchPosi = await getCoordinates(searchVal + " Nederland");
            }
            if(searchPosi) {
                apiUrl = `/wp-json/map/v1/locations?lat=${searchPosi.lat}&lng=${searchPosi.lng}&radius=${searchRaidus}`;
                position = searchPosi;
            }
        }

        // Create/refresh map
        map = new google.maps.Map(mapContainer, {
            center: position,
            zoom: 7,
            styles: [{ elementType: "all", stylers: [{ saturation: "-100" }]}]
        });

        // Clear any old markers
        markers.forEach(m => m.setMap(null));
        markers = [];

        try{
            const response = await fetch(apiUrl);
            let markerPoints = await response.json();

            if (searchPosi) {
                markerPoints.sort((a, b) => a.distance - b.distance);
            }

            // Always create markers on the map
            markerPoints.forEach((point, idx) => addMarker(point, idx + 1));

            // Build or hide the list depending on didSearch
            searchResultCon.innerHTML = "";
            if (didSearch && markerPoints.length > 0) {
                searchResultCon.style.display = "block";
                markerPoints.forEach((point, idx) => renderResultCard(point, idx + 1));
            } else {
                searchResultCon.style.display = "none";
            }

            // (Re)create clusterer with current markers
            if (clusterer) clusterer.setMap(null);
            clusterer = new MarkerClusterer({
                markers,
                map,
                renderer: {
                    render: ({ count, position }) => {
                        return new google.maps.Marker({
                            position,
                            icon: {
                                url: "https://www.afscheidmetbloemen.nl/wp-content/uploads/m1.png",
                                scaledSize: new google.maps.Size(50, 50),
                            },
                            label: {
                                text: count.toString(),
                                color: "white",
                                fontSize: "13px",
                                fontWeight: "bold",
                            },
                        });
                    }
                }
            });
        }catch(err){
            console.log("error");
        }
    }

    function getCoordinates(address) {
        return new Promise((resolve, reject) => {
            const geocoder = new google.maps.Geocoder();
            geocoder.geocode({ address: address }, (results, status) => {
                if (status === "OK") {
                    const location = results[0].geometry.location;
                    resolve({ lat: location.lat(), lng: location.lng() });
                } else {
                    reject(false);
                }
            });
        });
    }

    // Adds a marker (and info window + marker click) — does NOT add a list card
    function addMarker(eachMarker, displayIndex){
        const titleText = eachMarker?.title || "";

        const infoWin = new google.maps.InfoWindow({
            content: `
                <div class="info_window">
                    <div class="shop_nem">  ${eachMarker?.shop_name || ""} </div>
                    <div class="title">${titleText}</div>
                    <div class="address">${eachMarker?.address || ""}</div>
                    <a href="${eachMarker?.page_url || "#"}" class="sg_page_url">Bekijk locatie ></a>
                </div>
            `,
        });

        const marker = new google.maps.Marker({
            position: { lat: eachMarker?.lat, lng: eachMarker?.lng },
            title: titleText,
            icon: {
                url: "https://www.afscheidmetbloemen.nl/wp-content/uploads/image-1.png",
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
        markers.push(marker);
    }

    // Renders a result card (only called after a search)
    function renderResultCard(eachMarker, displayIndex){
        const eachLocationContainer = document.createElement("div");
        eachLocationContainer.classList.add("each_location");
        eachLocationContainer.innerHTML = `
            <div class="count_number"><span>${displayIndex}</span></div>
            <div class="location_body">
                <div class="shop_name">${eachMarker?.shop_name || ""}</div>
                <div class="title">${eachMarker?.title || ""}</div>
                <div class="address">${eachMarker?.address || ""}</div>
                <a href="${eachMarker?.page_url || "#"}" class="sg_page_url">Bekijk locatie ></a>
                <div class="distance">${(eachMarker?.distance ?? 0).toFixed ? eachMarker.distance.toFixed(2) : '0.00'} kilometers</div>
                <a href="https://maps.google.com/maps?saddr=${encodeURIComponent(searchVal || "")}&daddr=${encodeURIComponent(eachMarker?.address || "")}" class="rout_link">Route</a>
            </div>
        `;
        searchResultCon.appendChild(eachLocationContainer);

        // Hook up card click to open the corresponding marker info window (match by lat/lng)
        eachLocationContainer.addEventListener("click", function(e){
            if(e.target.tagName !== "A"){
                // Find the marker for this location
                const mk = markers.find(m =>
                    m.getPosition().lat() === eachMarker.lat &&
                    m.getPosition().lng() === eachMarker.lng
                );
                if (!mk) return;

                if(openInfoWindow) openInfoWindow.close();
                openInfoWindow = mk.__infoWindow;
                mk.__infoWindow.open(map, mk);
                map.setZoom(12);
                map.setCenter({ lat: eachMarker.lat, lng: eachMarker.lng });
                window.scrollTo({ top: mapContainer.getBoundingClientRect().top + window.scrollY - 250, behavior: "smooth" });
            }
        });
    }
</script>
<?php });

/* === Helpers + Data Collection === */
function get_distance($lat1, $lng1, $lat2, $lng2) {
    $R = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) * sin($dLng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}

function all_pin_data(){
    $args = array(
        'post_type'      => 'locaties',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    );
    $query = new WP_Query($args);

    $mapAddresses = [];

    if ($query->have_posts()){
        while ($query->have_posts())  {
            $query->the_post();
            $mapAddress = get_post_meta(get_the_ID(), 'google_map', true);

            // Include custom shop_name field
            $mapAddress["shop_name"] = get_post_meta(get_the_ID(), 'shop_name', true);

            $mapAddress["page_url"] = get_post_meta(get_the_ID(), 'external_page_url', true);
            $mapAddress["title"] = get_the_title();
            $mapAddress["distance"] = 0;
            $mapAddresses[] = $mapAddress;
        }
        wp_reset_postdata();
    }
    return $mapAddresses;
}

/* === REST Endpoint === */
function location_api_handler( WP_REST_Request $request ) {
    $lng = (float) $request->get_param('lng');
    $lat = (float) $request->get_param('lat');

    $all_locations = all_pin_data();

    if (empty($lng) || empty($lat)) return rest_ensure_response($all_locations);

    $radius = (float) $request->get_param('radius');
    if (empty($radius)) $radius = 2000;

    $locations = [];
    foreach($all_locations as $each_location){
        $distance = get_distance($lat, $lng, $each_location["lat"], $each_location["lng"]);
        if($distance <= $radius){
            $each_location["distance"] = $distance;
            $locations[] = $each_location;
        }
    }

    return rest_ensure_response($locations);
}

function map_location_api() {
    register_rest_route( 'map/v1', '/locations/', array(
        'methods'  => 'GET',
        'callback' => 'location_api_handler',
        'permission_callback' => '__return_true'
    ));
}
add_action( 'rest_api_init', 'map_location_api' );