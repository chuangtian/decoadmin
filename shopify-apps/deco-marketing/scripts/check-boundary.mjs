import fs from 'node:fs';
const guard=fs.readFileSync(new URL('../backend/Services/Guard.php',import.meta.url),'utf8');
if(!guard.includes("'macfox-test-app.myshopify.com'")||!guard.includes("config('marketing.environment') !== 'production'"))throw new Error('Pilot guard is missing');
console.log('Pilot boundary present; backend authorization tests are required before release.');
