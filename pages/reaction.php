<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Graduation Reactions</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Times New Roman', serif;
            background: linear-gradient(135deg, #0f1419 0%, #1a2332 50%, #2d3748 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .reaction-container {
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.95));
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 40px;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
            border: 3px solid #d4af37;
            max-width: 500px;
            width: 100%;
        }

        .session-title {
            font-size: 2.5rem;
            font-weight: bold;
            color: #d4af37;
            margin-bottom: 20px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.1);
        }

        .session-subtitle {
            font-size: 1.2rem;
            color: #4a5568;
            margin-bottom: 30px;
            line-height: 1.5;
        }

        .emoji-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin: 30px 0;
        }

        .emoji-button {
            background: linear-gradient(145deg, #ffffff, #f8fafc);
            border: 2px solid #d4af37;
            border-radius: 15px;
            padding: 20px;
            font-size: 2rem;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            user-select: none;
        }

        .emoji-button:hover {
            transform: translateY(-5px) scale(1.05);
            box-shadow: 0 10px 25px rgba(212, 175, 55, 0.3);
            border-color: #f4d03f;
        }

        .emoji-button:active {
            transform: translateY(-2px) scale(1.02);
        }

        .emoji-button.reacted {
            background: linear-gradient(145deg, #d4af37, #f4d03f);
            color: white;
            animation: pulse 0.6s ease-in-out;
            transform: scale(1.05);
        }

        .emoji-button.reacted:hover {
            transform: translateY(-5px) scale(1.1);
            box-shadow: 0 15px 30px rgba(212, 175, 55, 0.4);
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }

        .loading {
            display: none;
            margin: 20px 0;
        }

        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #d4af37;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .thank-you {
            display: none;
            color: #d4af37;
            font-size: 1.5rem;
            font-weight: bold;
            margin: 20px 0;
            animation: fadeInUp 0.5s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .error-message {
            color: #e74c3c;
            font-size: 1rem;
            margin: 10px 0;
            display: none;
        }

        @media (max-width: 768px) {
            .reaction-container {
                padding: 30px 20px;
            }
            
            .session-title {
                font-size: 2rem;
            }
            
            .session-subtitle {
                font-size: 1rem;
            }
            
            .emoji-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
            
            .emoji-button {
                padding: 15px;
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="reaction-container">
        <div class="session-title">🎓 Graduation Ceremony</div>
        <div class="session-subtitle">
            Send your reactions to celebrate this special moment!<br>
            Your reactions will appear on the main display screen.
        </div>
        
        <div class="emoji-grid">
            <button class="emoji-button" onclick="sendReaction('👏')">👏</button>
            <button class="emoji-button" onclick="sendReaction('🎉')">🎉</button>
            <button class="emoji-button" onclick="sendReaction('❤️')">❤️</button>
            <button class="emoji-button" onclick="sendReaction('🔥')">🔥</button>
            <button class="emoji-button" onclick="sendReaction('⭐')">⭐</button>
            <button class="emoji-button" onclick="sendReaction('🏆')">🏆</button>
            <button class="emoji-button" onclick="sendReaction('💪')">💪</button>
            <button class="emoji-button" onclick="sendReaction('🎓')">🎓</button>
        </div>
        
        <div class="loading" id="loading">
            <div class="loading-spinner"></div>
            <p>Sending reaction...</p>
        </div>
        
        <div class="thank-you" id="thankYou">
            Thank you for your reaction! 🎉
        </div>
        
        <div class="error-message" id="errorMessage"></div>
    </div>

    <script>
        // Track which buttons are currently processing to prevent multiple clicks
        const processingButtons = new Set();

        async function sendReaction(emoji) {
            const button = event.target;
            
            // Prevent multiple rapid clicks on the same button
            if (processingButtons.has(button)) {
                return;
            }
            
            const loading = document.getElementById('loading');
            const thankYou = document.getElementById('thankYou');
            const errorMessage = document.getElementById('errorMessage');
            
            try {
                // Mark button as processing
                processingButtons.add(button);
                
                // Show loading
                loading.style.display = 'block';
                thankYou.style.display = 'none';
                errorMessage.style.display = 'none';
                
                // Add reacted class to button
                button.classList.add('reacted');
                
                const response = await fetch('/api/submit_reaction.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        emoji: emoji
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Hide loading and show thank you
                    loading.style.display = 'none';
                    thankYou.style.display = 'block';
                    
                    // Keep the reacted class for a moment for visual feedback
                    setTimeout(() => {
                        button.classList.remove('reacted');
                    }, 1000);
                    
                    // Hide thank you after 2 seconds
                    setTimeout(() => {
                        thankYou.style.display = 'none';
                    }, 2000);
                } else {
                    throw new Error(data.message || 'Failed to send reaction');
                }
            } catch (error) {
                loading.style.display = 'none';
                errorMessage.textContent = 'Error: ' + error.message;
                errorMessage.style.display = 'block';
                
                // Remove reacted class
                button.classList.remove('reacted');
                
                // Hide error after 5 seconds
                setTimeout(() => {
                    errorMessage.style.display = 'none';
                }, 5000);
            } finally {
                // Remove button from processing set
                processingButtons.delete(button);
            }
        }
    </script>
</body>
</html> 
