export const wordpressConfig = {
  url: (process.env.WORDPRESS_URL || 'http://wordpress').trim(),
  apiKey: (process.env.WORDPRESS_API_KEY || process.env.ACM_MASTER_API_KEY || '').trim(),
  endpoints: {
    campaignData: (agendamentoId: string) =>
      `${wordpressConfig.url}/wp-json/campaigns/v1/data/${agendamentoId}`,
    credentials: (provider: string, envId: string) =>
      `${wordpressConfig.url}/wp-json/api-manager/v1/credentials/${provider}/${envId}`,
    campaignConfig: (agendamentoId: string) =>
      `${wordpressConfig.url}/wp-json/campaigns/v1/config/${agendamentoId}`,
    webhookStatus: () =>
      `${wordpressConfig.url}/wp-json/webhook-status/v1/update`,
  },
};

