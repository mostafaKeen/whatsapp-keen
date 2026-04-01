const fs = require('fs');
const path = require('path');
const axios = require('axios');

class BitrixManager {
    constructor() {
        this.basePath = path.join(__dirname, 'data', 'portals');
        if (!fs.existsSync(this.basePath)) {
            fs.mkdirSync(this.basePath, { recursive: true });
        }
    }

    getAuthPath(domain) {
        return path.join(this.basePath, `${domain.replace(/[^a-z0-9.-]/gi, '_')}.json`);
    }

    async saveAuth(domain, authData) {
        const filePath = this.getAuthPath(domain);
        const data = {
            domain,
            auth_id: authData.AUTH_ID,
            refresh_id: authData.REFRESH_ID,
            expires: Date.now() + (authData.AUTH_EXPIRES * 1000),
            server_endpoint: authData.SERVER_ENDPOINT || `https://${domain}/rest/`,
            updated_at: new Date().toISOString()
        };
        fs.writeFileSync(filePath, JSON.stringify(data, null, 2));
        return data;
    }

    async getAuth(domain) {
        const filePath = this.getAuthPath(domain);
        if (!fs.existsSync(filePath)) return null;
        return JSON.parse(fs.readFileSync(filePath, 'utf8'));
    }

    async verifyAuth(domain, authId) {
        try {
            const url = `https://${domain}/rest/app.info?auth=${authId}`;
            const response = await axios.get(url);
            return response.data && !response.data.error;
        } catch (err) {
            console.error(`[Bitrix] Auth verification failed for ${domain}:`, err.message);
            return false;
        }
    }

    async callMethod(domain, method, params = {}, authId) {
        const auth = await this.getAuth(domain);
        if (!auth && !authId) throw new Error(`No auth found for domain ${domain}`);
        
        const token = authId || auth.auth_id;
        const endpoint = auth ? auth.server_endpoint : `https://${domain}/rest/`;
        
        try {
            const response = await axios.post(`${endpoint}${method}`, {
                ...params,
                auth: token
            });
            return response.data;
        } catch (err) {
            console.error(`[Bitrix] API call failed (${method}):`, err.response?.data || err.message);
            throw err;
        }
    }

    async getCrmEntity(domain, entityType, entityId, authId) {
        const method = entityType === 'deal' ? 'crm.deal.get' : 'crm.lead.get';
        const result = await this.callMethod(domain, method, { id: entityId }, authId);
        
        if (!result.result) return null;
        const entity = result.result;
        
        let phone = '';
        let name = `${entity.NAME || ''} ${entity.LAST_NAME || ''}`.trim() || entity.TITLE || 'Unknown';
        
        if (entity.PHONE && entity.PHONE.length > 0) {
            phone = entity.PHONE[0].VALUE;
        } else if (entityType === 'deal' && entity.CONTACT_ID) {
            // Fetch contact phone if deal has no phone but has contact
            const contactResult = await this.callMethod(domain, 'crm.contact.get', { id: entity.CONTACT_ID }, authId);
            if (contactResult.result?.PHONE?.length > 0) {
                phone = contactResult.result.PHONE[0].VALUE;
                if (name === 'Unknown') {
                    name = `${contactResult.result.NAME || ''} ${contactResult.result.LAST_NAME || ''}`.trim();
                }
            }
        }
        
        return { phone, name };
    }
}

module.exports = new BitrixManager();
