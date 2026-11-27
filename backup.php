<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Website Backup System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366f1;
            --primary-hover: #4f46e5;
            --bg: #0f172a;
            --card-bg: #1e293b;
            --text: #f8fafc;
            --text-muted: #94a3b8;
            --success: #22c55e;
            --error: #ef4444;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg);
            color: var(--text);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            background-color: var(--card-bg);
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 500px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        h1 {
            font-size: 1.8rem;
            margin-bottom: 10px;
            font-weight: 600;
        }

        p {
            color: var(--text-muted);
            margin-bottom: 30px;
            font-size: 0.95rem;
        }

        .backup-btn {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 15px 30px;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .backup-btn:hover {
            background-color: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);
        }

        .backup-btn:disabled {
            background-color: #475569;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .status-area {
            margin-top: 20px;
            padding: 20px;
            border-radius: 12px;
            background: rgba(15, 23, 42, 0.5);
            display: none;
        }

        .loader {
            width: 24px;
            height: 24px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top: 3px solid #fff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .success-msg { color: var(--success); font-weight: 600; }
        .error-msg { color: var(--error); font-weight: 600; }

        .download-link {
            display: inline-block;
            margin-top: 15px;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            border-bottom: 2px solid transparent;
            transition: border-color 0.3s;
        }

        .download-link:hover {
            border-color: var(--primary);
        }

        .token-input {
            width: 100%;
            padding: 12px;
            background: rgba(15, 23, 42, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            color: white;
            margin-bottom: 20px;
            font-family: inherit;
        }

        .token-input:focus {
            outline: 2px solid var(--primary);
            border-color: transparent;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Website Backup</h1>
        <p>One-click system for files and database</p>

        <input type="password" id="token" class="token-input" placeholder="Enter Security Token">
        
        <button id="backupBtn" class="backup-btn">
            <span id="btnText">Generate Backup</span>
        </button>

        <div id="statusArea" class="status-area">
            <div id="loader" class="loader" style="margin: 0 auto 15px; display: none;"></div>
            <div id="statusText"></div>
            <div id="resultArea"></div>
        </div>
    </div>

    <script>
        const backupBtn = document.getElementById('backupBtn');
        const tokenInput = document.getElementById('token');
        const statusArea = document.getElementById('statusArea');
        const statusText = document.getElementById('statusText');
        const resultArea = document.getElementById('resultArea');
        const loader = document.getElementById('loader');
        const btnText = document.getElementById('btnText');

        backupBtn.addEventListener('click', async () => {
            const token = tokenInput.value.trim();
            if (!token) {
                alert('Please enter the security token.');
                return;
            }

            // Reset UI
            statusArea.style.display = 'block';
            statusText.innerHTML = 'Starting backup process... Please wait.';
            statusText.className = '';
            resultArea.innerHTML = '';
            loader.style.display = 'block';
            backupBtn.disabled = true;
            btnText.innerText = 'Backing up...';

            try {
                const response = await fetch(`backup_processor.php?token=${encodeURIComponent(token)}`);
                const data = await response.json();

                loader.style.display = 'none';

                if (data.status === 'success') {
                    statusText.innerHTML = 'Backup completed successfully!';
                    statusText.className = 'success-msg';
                    resultArea.innerHTML = `
                        <p style="margin-top: 10px;">Size: ${data.size}</p>
                        <a href="download_backup.php?file=${encodeURIComponent(data.file)}&token=${encodeURIComponent(token)}" class="download-link">Download ZIP Archive</a>
                    `;
                } else {
                    throw new Error(data.message || 'Backup failed');
                }
            } catch (error) {
                loader.style.display = 'none';
                statusText.innerHTML = 'Error: ' + error.message;
                statusText.className = 'error-msg';
            } finally {
                backupBtn.disabled = false;
                btnText.innerText = 'Generate Backup';
            }
        });
    </script>
</body>
</html>
