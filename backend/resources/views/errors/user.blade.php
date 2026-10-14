<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TechStore</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; font-family: Segoe UI, sans-serif; background: #f4efe6; color: #1b1b18; }
        main { width: min(28rem, calc(100% - 2rem)); background: #fff; border-radius: 16px; padding: 1.75rem; box-shadow: 0 8px 24px #00000014; }
        p { margin: 0 0 0.75rem; color: #5c5850; }
        h1 { margin: 0 0 0.75rem; font-size: 1.35rem; }
        a { color: #8a4b12; }
    </style>
</head>
<body>
    <main>
        <p>TechStore</p>
        <h1>No se pudo completar la operación</h1>
        <p>{{ $message }}</p>
        <a href="/">Volver al inicio</a>
    </main>
</body>
</html>
