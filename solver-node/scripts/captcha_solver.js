#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');
const https = require('https');
const crypto = require('crypto');

class CaptchaSolver {
    constructor() {
        this.apiKey = process.env.SLOT_API_KEY;
        this.portalUrl = process.env.PORTAL_URL || 'https://ipms.senda.fit';
        this.nodeId = crypto.randomBytes(16).toString('hex');
        this.isRunning = true;
        
        // Validate configuration
        if (!this.apiKey) {
            throw new Error('SLOT_API_KEY environment variable is required');
        }
    }
    
    log(level, message) {
        const timestamp = new Date().toISOString();
        const logEntry = `[${timestamp}] [${level}] ${message}\n`;
        
        const logDir = '/opt/ipms-solver/logs';
        if (!fs.existsSync(logDir)) {
            fs.mkdirSync(logDir, { recursive: true });
        }
        
        fs.appendFileSync(`${logDir}/solver.log`, logEntry);
        console.log(`[${level}] ${message}`);
    }
    
    async makeRequest(endpoint, method = 'GET', data = null) {
        const url = new URL(`${this.portalUrl}${endpoint}`);
        
        const options = {
            hostname: url.hostname,
            port: url.port || 443,
            path: url.pathname,
            method: method,
            headers: {
                'Authorization': `Bearer ${this.apiKey}`,
                'Content-Type': 'application/json',
                'User-Agent': `IPMS-Solver/${this.nodeId}`
            }
        };
        
        if (data) {
            const jsonString = JSON.stringify(data);
            options.headers['Content-Length'] = Buffer.byteLength(jsonString);
        }
        
        return new Promise((resolve, reject) => {
            const req = https.request(options, (res) => {
                let responseData = '';
                
                res.on('data', (chunk) => {
                    responseData += chunk;
                });
                
                res.on('end', () => {
                    try {
                        const parsed = responseData ? JSON.parse(responseData) : {};
                        resolve({ 
                            status: res.statusCode, 
                            data: parsed 
                        });
                    } catch (e) {
                        resolve({ 
                            status: res.statusCode, 
                            data: responseData 
                        });
                    }
                });
            });
            
            req.on('error', reject);
            
            if (data) {
                req.write(JSON.stringify(data));
            }
            
            req.end();
        });
    }
    
    async fetchWork() {
        try {
            const response = await this.makeRequest('/api/captcha-nodes/fetch', 'POST', {
                nodeId: this.nodeId
            });
            
            if (response.status === 200) {
                return response.data;
            } else if (response.status === 204) {
                return null;
            } else {
                this.log('ERROR', `Fetch failed: ${response.status}`);
                return null;
            }
        } catch (error) {
            this.log('ERROR', `Network error in fetchWork: ${error.message}`);
            return null;
        }
    }
    
    async solveCaptcha(task) {
        this.log('INFO', `Processing captcha task: ${task.id}`);
        
        // Actual implementation would include:
        // 1. Fetch captcha image/data
        // 2. Apply ML model or connect to solver service
        // 3. Return solved text
        
        // Simulated solution for demonstration
        return {
            taskId: task.id,
            solution: crypto.randomBytes(6).toString('hex'),
            confidence: 0.95
        };
    }
    
    async submitResult(result) {
        try {
            await this.makeRequest('/api/captcha-nodes/result', 'POST', result);
            this.log('INFO', `Task completed: ${result.taskId}`);
        } catch (error) {
            this.log('ERROR', `Failed to submit result: ${error.message}`);
        }
    }
    
    async run() {
        this.log('INFO', `Solver node started (ID: ${this.nodeId})`);
        
        while (this.isRunning) {
            try {
                const work = await this.fetchWork();
                
                if (work && work.task) {
                    const result = await this.solveCaptcha(work.task);
                    await this.submitResult(result);
                } else {
                    // No work available, wait a bit
                    await new Promise(resolve => setTimeout(resolve, 1000));
                }
            } catch (error) {
                this.log('ERROR', `Processing error: ${error.message}`);
                await new Promise(resolve => setTimeout(resolve, 5000)); // Back off on errors
            }
        }
        
        this.log('INFO', 'Solver node stopped');
    }
    
    stop() {
        this.isRunning = false;
        this.log('INFO', 'Stopping solver node...');
    }
}

// Handle graceful shutdown
const solver = new CaptchaSolver();

process.on('SIGTERM', () => solver.stop());
process.on('SIGINT', () => solver.stop());

// Start the solver
solver.run().catch((error) => {
    console.error('Fatal error:', error);
    process.exit(1);
});
