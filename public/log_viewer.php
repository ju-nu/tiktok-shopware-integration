<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Live Log Viewer</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        #log-container {
            height: 80vh;
            overflow-y: scroll;
            background-color: #111827;
            color: #d1d5db;
            font-family: monospace;
            padding: 1rem;
            border-radius: 0.5rem;
        }
    </style>
</head>
<body class="bg-gray-100 p-8">
    <div class="max-w-6xl mx-auto">
        <h1 class="text-2xl font-bold mb-4 text-center">📄 Live Log Viewer</h1>
        <pre id="log-container"></pre>
    </div>

    <script>
        const container = document.getElementById('log-container');

        function fetchLogs() {
            fetch('/log.php')
                .then(res => res.text())
                .then(text => {
                    container.textContent = text;
                    container.scrollTop = container.scrollHeight; // Auto-scroll
                });
        }

        fetchLogs(); // Initial load
        setInterval(fetchLogs, 1000); // Refresh every 5s
    </script>
</body>
</html>
