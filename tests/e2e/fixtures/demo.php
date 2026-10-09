<?php
/**
 * Demo site "Atelier Grün": what real sites load from third parties.
 */
add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'atelier-fonts', 'https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Inter:wght@400;500&display=swap', array(), null );
	wp_enqueue_script( 'swiper', 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js', array(), null, true );
	wp_add_inline_style( 'atelier-fonts', 'body{font-family:Inter,sans-serif}h1,h2,h3{font-family:Fraunces,serif}' );
} );
add_filter( 'wp_resource_hints', function ( $urls, $type ) {
	if ( 'preconnect' === $type ) {
		$urls[] = array( 'href' => 'https://fonts.gstatic.com', 'crossorigin' );
	}
	return $urls;
}, 10, 2 );
add_action( 'wp_head', function () {
	?>
<script async src="https://www.googletagmanager.com/gtag/js?id=G-7XQ2L8TEST"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-7XQ2L8TEST');
</script>
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window, document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '1234567890');
fbq('track', 'PageView');
</script>
<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=1234567890&ev=PageView&noscript=1"/></noscript>
	<?php
}, 20 );
add_action( 'wp_footer', function () {
	echo '<script src="https://cdn.trustindex.io/loader.js?8c1f2e3a" defer async></script>' . "\n";
} );
