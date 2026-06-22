(function () {
    var toggle = document.getElementById('ls-nav-toggle');
    var nav    = document.getElementById('ls-nav');
    if ( toggle && nav ) {
        toggle.addEventListener('click', function () {
            var open = nav.classList.toggle('nav-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            document.body.classList.toggle('nav-is-open', open);
        });
        document.addEventListener('click', function (e) {
            if ( nav.classList.contains('nav-open') && ! nav.contains(e.target) && e.target !== toggle ) {
                nav.classList.remove('nav-open');
                toggle.setAttribute('aria-expanded', 'false');
                document.body.classList.remove('nav-is-open');
            }
        });
    }
}());

(function () {
    var shapes = document.querySelectorAll('.shape');
    if ( ! shapes.length ) return;

    var ticking = false;

    function updateShapes() {
        var scrollY       = window.scrollY;
        var scrollPercent = scrollY / Math.max( 1, document.body.scrollHeight - window.innerHeight );

        shapes.forEach( function ( shape, index ) {
            var speed = ( index % 3 + 1 ) * 0.5;
            var dir   = index % 2 === 0 ? 1 : -1;
            var ty    = scrollY * speed * 0.3 * dir;
            var tx    = Math.sin( scrollPercent * Math.PI * 2 + index ) * 30;
            shape.style.transform = 'translate3d(' + tx + 'px,' + ty + 'px,0)';
        } );

        ticking = false;
    }

    window.addEventListener( 'scroll', function () {
        if ( ! ticking ) {
            requestAnimationFrame( updateShapes );
            ticking = true;
        }
    }, { passive: true } );

    updateShapes();
}());
