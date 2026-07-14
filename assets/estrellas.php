<!-- Fondo con estrellas (lluvia) -->
<canvas id="stars-canvas" style="position:fixed;top:0;left:0;width:100%;height:100%;z-index:0;pointer-events:none;"></canvas>
<script>
(function() {
    const canvas = document.getElementById('stars-canvas');
    const ctx = canvas.getContext('2d');
    let width, height;
    function resize() {
        width = canvas.width = window.innerWidth;
        height = canvas.height = window.innerHeight;
    }
    window.addEventListener('resize', resize);
    resize();
    const stars = [];
    const NUM_STARS = 180;
    for (let i = 0; i < NUM_STARS; i++) {
        stars.push({
            x: Math.random() * width,
            y: Math.random() * height,
            size: Math.random() * 2.5 + 0.5,
            speed: Math.random() * 2 + 0.5,
            alpha: Math.random() * 0.7 + 0.3
        });
    }
    function draw() {
        ctx.clearRect(0, 0, width, height);
        stars.forEach(s => {
            s.y += s.speed;
            if (s.y > height) {
                s.y = -5;
                s.x = Math.random() * width;
                s.speed = Math.random() * 2 + 0.5;
                s.size = Math.random() * 2.5 + 0.5;
                s.alpha = Math.random() * 0.7 + 0.3;
            }
            ctx.beginPath();
            ctx.arc(s.x, s.y, s.size, 0, Math.PI * 2);
            ctx.fillStyle = `rgba(255,255,255,${s.alpha})`;
            ctx.shadowColor = 'rgba(180,220,255,0.8)';
            ctx.shadowBlur = 15;
            ctx.fill();
        });
        ctx.shadowBlur = 0;
        requestAnimationFrame(draw);
    }
    draw();
})();
</script>