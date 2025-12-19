// ------------------------------
// CellQuest.js - Fully Integrated
// ------------------------------

let gameState = null;
let isRunning = false;
let interval = null;
let loopRunning = false;
let loopInterval = null;

// UI elements
const gameButton   = document.getElementById("gameButton");
const resetButton  = document.getElementById("resetButton");
const loopButton = document.getElementById("loopButton");
const counterSpan  = document.getElementById("counter");
const canvasD      = document.getElementById("canvasDiv");
const canvasT      = document.getElementById("canvasTDiv");
const canvasP      = document.getElementById("canvasPDiv");

const mapDiv       = document.getElementById("map");
const scoreDiv     = document.getElementById("score");
const clientOut    = document.getElementById("clientOut");
const serverOut    = document.getElementById("serverOut");

// ------------------------------
// INIT
// ------------------------------
window.addEventListener("load", fetchState);

gameButton.addEventListener("click", () => {
    if (!isRunning) startSimulation();
    else stopSimulation();
});
resetButton.addEventListener("click", resetGame);
loopButton.addEventListener("click", toggleLoop);

function toggleLoop() {
    if (loopRunning) {
        // STOP THE LOOP
        loopRunning = false;
        stopSimulation(); // stops current game
        clearInterval(loopInterval);
        loopInterval = null;
        loopButton.innerText = "Loop";
        return;
    }

    // START THE LOOP
    loopRunning = true;
    loopButton.innerText = "STOP";

    // First reset immediately
    fetch("ServerCQ.php?action=reset")
        .then(r => r.json())
        .then(data => {
            gameState = data;
            renderMap();
            renderScore();
            counterSpan.innerText = gameState.reloadCount;
            startSimulation(); // runs rounds normally

            // Now watch for Finished and restart
            loopInterval = setInterval(() => {
                if (!loopRunning) return;

                if (gameState.Finished === true) {
                    stopSimulation(); // stop existing game loop

                    fetch("ServerCQ.php?action=reset")
                        .then(r => r.json())
                        .then(data => {
                            gameState = data;
                            renderMap();
                            renderScore();
                            counterSpan.innerText = gameState.reloadCount;
                            startSimulation();
                        });
                }
            }, 500); // checks twice per second, calm down
        });
}
// ------------------------------
// SERVER COMMUNICATION
// ------------------------------
async function saveSettings() {
    if (!gameState) return;
    console.log("SAVING SETTINGS:", gameState); // <--- debug line
    const payload = JSON.stringify(gameState, null, 2);
    debugPrint("CLIENT SENT:\n" + payload, null);

    try {
        const res = await fetch("ServerCQ.php?action=round", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: payload
        });

        const text = await res.text();
        debugPrint(null, "SERVER REPLIED:\n" + text);

        try {
            gameState = JSON.parse(text);
        } catch {
            console.warn("Server returned non-JSON");
        }
    } catch (err) {
        console.error("Failed to save settings:", err);
    }
}

function debugPrint(clientSent, serverGot) {
    if (clientSent) clientOut.innerText = clientSent;
    if (serverGot)  serverOut.innerText = serverGot;
}

// ------------------------------
// SETTINGS HANDLERS - FOOLPROOF
// ------------------------------
function bindSettingControls() {
    if (!gameState) return;

    // Explicit mapping of teams to gameState keys
    const teamMap = {
        blue: "blueSettings",
        red: "redSettings",
        yellow: "yellowSettings",
        green: "greenSettings",
        purple:"purpleSettings"
    };

    // Remove old listeners by cloning selects
    document.querySelectorAll("select[id^='team_']").forEach(sel => {
        const newSel = sel.cloneNode(true);
        sel.parentNode.replaceChild(newSel, sel);
    });

    // Bind each select to the correct gameState array
    document.querySelectorAll("select[id^='team_']").forEach(select => {
        const [_, team, prop] = select.id.split("_"); // e.g., team_blue_agr -> ["team","blue","agr"]
        if (!team || !prop) return;

        const arrName = teamMap[team.toLowerCase()];
        if (!arrName) return console.warn("No settings array found for team:", team);

        // Ensure the array exists
        gameState[arrName] = Array.isArray(gameState[arrName]) ? gameState[arrName] : [0,0,0];

        // Map select type to array index
        const idxMap = { agr: 0, def: 1, vrd: 2 };
        const idx = idxMap[prop.toLowerCase()];
        if (idx === undefined) return;

        // Initialize select with current value
        select.value = gameState[arrName][idx];

        // Update gameState and save whenever changed
        select.addEventListener("change", e => {
            console.log("ARR NAME:", arrName);
            console.log("CURRENT STATE:", gameState[arrName]);
            console.log("INDEX:", idx, "VALUE:", e.target.value);

            if (!gameState[arrName]) {
                console.error("Cannot update: gameState array does not exist!");
                return;
            }

            gameState[arrName][idx] = Number(e.target.value);
            saveSettings();
        });

    });
}

// ------------------------------
// FETCH & RESET - FORCE UI SYNC
// ------------------------------
function fetchState() {
    fetch("ServerCQ.php?action=get")
        .then(r => r.json())
        .then(data => {
        gameState = data;
        if (!gameState.blueSettings) gameState.blueSettings = [0,0,0];
        if (!gameState.redSettings) gameState.redSettings = [0,0,0];
        if (!gameState.yellowSettings) gameState.yellowSettings = [0,0,0];
        if (!gameState.greenSettings) gameState.greenSettings = [0,0,0];
        if (!gameState.purpleSettings) gameState.purpleSettings = [0,0,0];
        renderMap();
        renderScore();
        counterSpan.innerText = gameState.reloadCount;
        bindSettingControls();
        syncSelectsWithState();
        stopSimulation();
    });
}

function resetGame() {
    stopSimulation(); // ensure no further ticks occur

    fetch("ServerCQ.php?action=reset")
        .then(r => r.json())
        .then(data => {
            gameState = data;
            if (!gameState.blueSettings) gameState.blueSettings = [0,0,0];
            if (!gameState.redSettings) gameState.redSettings = [0,0,0];
            if (!gameState.yellowSettings) gameState.yellowSettings = [0,0,0];
            if (!gameState.greenSettings) gameState.greenSettings = [0,0,0];
            if (!gameState.purpleSettings) gameState.purpleSettings = [0,0,0];
            console.log("RESET COORDSTRING SAMPLE:", gameState.coordString.slice(0,200));
            counterSpan.innerText = gameState.reloadCount;
            bindSettingControls();  // rebind listeners
            syncSelectsWithState(); // force selects to state values
            stopSimulation();
            renderMap();
            renderScore();
        });
}

// ------------------------------
// FORCE SELECTS TO MATCH STATE
// ------------------------------
function syncSelectsWithState() {
    const teamMap = {
        blue: "blueSettings",
        red: "redSettings",
        yellow: "yellowSettings",
        green: "greenSettings",
        purple: "purpleSettings"
    };

    document.querySelectorAll("select[id^='team_']").forEach(select => {
        const [_, team, prop] = select.id.split("_");
        if (!team || !prop) return;

        const arrName = teamMap[team.toLowerCase()];
        if (!arrName) return;

        const idxMap = { agr: 0, def: 1, vrd: 2 };
        const idx = idxMap[prop.toLowerCase()];
        if (idx === undefined) return;

        select.value = gameState[arrName][idx];
    });
}

// ------------------------------
// SIMULATION LOOP
// ------------------------------
function startSimulation() {
    isRunning = true;
    gameButton.innerText = "Pause";
    interval = setInterval(runOneRound, 50);
}

function stopSimulation() {
    isRunning = false;
    gameButton.innerText = "Start";
    renderMap();
    clearInterval(interval);
}

async function runOneRound() {
    if (!gameState) return;

    const payload = JSON.stringify(gameState, null, 2);
    debugPrint("CLIENT SENT:\n" + payload, null);

    try {
        const r = await fetch("ServerCQ.php?action=round", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: payload
        });

        const txt = await r.text();
        debugPrint(null, "SERVER REPLIED:\n" + txt);

        try { gameState = JSON.parse(txt); } catch {}

        if (gameState.Finished === true) {
            stopSimulation();
            console.log("Simulation stopped: server reports Finished = true");
            return;
        }

        renderMap();
        renderScore();
        counterSpan.innerText = gameState.reloadCount;
    } catch (err) {
        console.error("Round failed:", err);
    }
}

// ------------------------------
// DISPLAY
// ------------------------------
function renderMap() {
    if (!gameState || !gameState.coordString) {
        //Canvas Version
        canvasD.innerHTML = "Canvas missing.";
        canvasT.innerHTML = "Terrain Canvas missing.";
        canvasP.innerHTML = "Population Canvas missing.";

        return;
        //    
    }

    const cells = gameState.coordString.split(",");
    const size = gameState.oneSide;
    const cellSz = gameState.cellSize; 

    //Canvas Version
    canvasD.innerHTML = '<canvas id="canvas" width="'+cellSz*size+'" height="'+cellSz*size+'"></canvas>';
    canvasT.innerHTML = '<canvas id="canvasTerr" width="'+cellSz*size+'" height="'+cellSz*size+'"></canvas>';  
    canvasP.innerHTML = '<canvas id="canvasPop" width="'+cellSz*size+'" height="'+cellSz*size+'"></canvas>';  


    const canvas = document.getElementById("canvas");
    const ctx = canvas.getContext("2d");

    const terrainCanvas = document.getElementById("canvasTerr"); // rename local variable
    const ctxT = terrainCanvas.getContext("2d");

    const popCanvas = document.getElementById("canvasPop"); // rename local variable
    const ctxP = popCanvas.getContext("2d");

    for (let y = 0; y < size; y++) {
        for (let x = 0; x < size; x++) {

            const owner = cells[y*size+x].split(".")[1];
            const terrainType = cells[y*size+x].split(".")[4];
            const population = cells[y*size+x].split(".")[2];
            function isBorderCell(x, y, size, cells) {
                const index = y * size + x;
                const owner = cells[index].split(".")[1];

                if (owner === "None") return false;

                const dirs = [
                    [1, 0],
                    [-1, 0],
                    [0, 1],
                    [0, -1]
                ];

                for (const [dx, dy] of dirs) {
                    const nx = (x + dx + size) % size;
                    const ny = (y + dy + size) % size;

                    const nOwner = cells[ny * size + nx].split(".")[1];

                    if (nOwner !== owner) return true;
                }
                return false;
            }

            transparency = 1;
            redBackground = 300 - terrainType; // Assuming terrainType is defined elsewhere
            greenBackground = 100;
            blueBackground = 0; 
            const c = 'rgb('+redBackground+','+greenBackground+','+blueBackground+','+transparency+')';     
            const p = 'rgb('+population/1000*255+','+population/1000*255+',255)';
            ctx.fillStyle = c || "#302e2eff";
            ctxT.fillStyle = c || "#0c0c0cff";
            ctxP.fillStyle = p || "#0c0c0cff";      

            ctx.fillRect(x*cellSz-cellSz, y*cellSz-cellSz, cellSz, cellSz);  
            ctxT.fillRect(x*cellSz/2-cellSz/2, y*cellSz/2-cellSz/2, cellSz/2, cellSz/2);
            ctxP.fillRect(x*cellSz/2-cellSz/2, y*cellSz/2-cellSz/2, cellSz/2, cellSz/2);
            const o = owner === "TeamBlue" ? "blue"
                    : owner === "TeamRed" ? "red"
                    : owner === "TeamYellow" ? "yellow"
                    : owner === "TeamGreen" ? "green"
                    : owner === "TeamPurple" ? "purple"
                    : "#ccc";
            
               
            if (isBorderCell(x, y, size, cells)) {
                ctx.strokeStyle = o;
                ctx.lineWidth = 3;
                ctx.strokeRect(x*cellSz-cellSz, y*cellSz-cellSz, cellSz, cellSz);
            }

            ctx.beginPath();
            let radius = Math.max(0, cellSz * population / 9001);
            ctx.arc(x*cellSz - cellSz, y*cellSz - cellSz, radius, 0, 2*Math.PI);

            ctx.fillStyle = "rgba(187, 187, 196, 0.1)";
            ctx.fill();
            
        }

    }
}

function renderScore() {
    if (!gameState || !gameState.teamCounts) return;

    const c = gameState.teamCounts;
    scoreDiv.innerHTML = `
        Blue: ${c.blue} | Red: ${c.red} | Yellow: ${c.yellow} | Green: ${c.green} | Purple: ${c.purple}
    `;
}
