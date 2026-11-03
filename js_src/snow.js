(function () {
    let isTabActive = true;
    document.addEventListener("visibilitychange", () => {
        isTabActive = !document.hidden;
    });

    document.addEventListener("DOMContentLoaded", () => {
        const canvas = document.createElement("canvas");
        canvas.id = "snow-canvas";
        canvas.style.position = "fixed";
        canvas.style.top = "0";
        canvas.style.left = "0";
        canvas.style.width = "100vw";
        canvas.style.height = "100vh";
        canvas.style.pointerEvents = "none";
        canvas.style.zIndex = "99998";
        document.body.appendChild(canvas);

        const ctx = canvas.getContext("2d");
        let width = (canvas.width = window.innerWidth);
        let height = (canvas.height = window.innerHeight);

        const isMobile = window.innerWidth <= 600;
        const flakeCount = isMobile ? 25 : 60;
        const flakes = [];

        function resetFlake(flake, init = false) {
            flake.x = Math.random() * width;
            flake.y = init ? Math.random() * height : -10;
            flake.r = Math.random() * 2.2 + 1;       // Radius: 1.0px - 3.2px
            flake.speedY = Math.random() * 1.2 + 0.6; // Fall speed
            flake.speedX = Math.random() * 0.6 - 0.3; // Wind
            flake.opacity = Math.random() * 0.5 + 0.2;
            flake.swingAngle = Math.random() * Math.PI * 2;
            flake.swingSpeed = Math.random() * 0.02 + 0.01;
        }

        for (let i = 0; i < flakeCount; i++) {
            const flake = {};
            resetFlake(flake, true);
            flakes.push(flake);
        }

        window.addEventListener("resize", () => {
            width = canvas.width = window.innerWidth;
            height = canvas.height = window.innerHeight;
        });

        function render() {
            if (isTabActive) {
                ctx.clearRect(0, 0, width, height);

                for (let i = 0; i < flakeCount; i++) {
                    const f = flakes[i];

                    f.swingAngle += f.swingSpeed;
                    f.x += Math.sin(f.swingAngle) * 0.5 + f.speedX;
                    f.y += f.speedY;

                    ctx.beginPath();
                    ctx.arc(f.x, f.y, f.r, 0, Math.PI * 2);
                    ctx.fillStyle = `rgba(255, 255, 255, ${f.opacity})`;
                    ctx.fill();

                    if (f.y > height + 10 || f.x < -10 || f.x > width + 10) {
                        resetFlake(f, false);
                    }
                }
            }
            requestAnimationFrame(render);
        }

        requestAnimationFrame(render);
    });
})();