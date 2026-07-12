export interface BrandData {
  name: string;
  englishName: string;
  domain: string;
  region: string;
  category: string;
  emotionalClaim: string;
  professionalClaim: string;
}

export interface SiteConfig {
  formEndpoint?: string;
  formApiKey?: string;
  formSubject?: string;
  phone?: string;
  address?: string;
  businessHours?: string;
  wechatQr?: string;
  isProduction: boolean;
}

export interface CaseStudy {
  slug: string;
  title: string;
  space: string;
  decision: string;
  summary: string;
  image: string;
  alt: string;
  conceptual: true;
}

export const brand: BrandData = {
  name: '乐宅.Life',
  englishName: 'LEZHAI',
  domain: 'https://lezhai.life',
  region: '惠州',
  category: '惠州本地设计型门窗服务商',
  emotionalClaim: '把喜欢的门，装进生活',
  professionalClaim: '好设计，更要好落地',
};

export const siteConfig: SiteConfig = {
  formEndpoint: import.meta.env.PUBLIC_FORM_ENDPOINT || undefined,
  formApiKey: import.meta.env.PUBLIC_STATICFORMS_API_KEY || undefined,
  formSubject: import.meta.env.PUBLIC_FORM_SUBJECT || undefined,
  phone: import.meta.env.PUBLIC_PHONE || '13530067877',
  address: import.meta.env.PUBLIC_ADDRESS || '广东省惠州市仲恺区香樟小镇 D10 铺',
  businessHours: import.meta.env.PUBLIC_BUSINESS_HOURS || undefined,
  wechatQr: import.meta.env.PUBLIC_WECHAT_QR || undefined,
  isProduction: import.meta.env.PUBLIC_SITE_ENV === 'production',
};

export const navigation = [
  { href: '/services/', label: '服务流程' },
  { href: '/cooperation/', label: '装修公司合作' },
  { href: '/about/', label: '关于乐宅' },
];

export const caseStudies: CaseStudy[] = [
  {
    slug: 'entry-door',
    title: '让入户第一眼，安静而有分量',
    space: '玄关 / 入户',
    decision: '以深胡桃木门扇和克制的黑色长拉手建立入户秩序，同时校核门洞、开向、门槛与收口关系。',
    summary: '从门体比例、开启动线与玄关材质出发，让入户大门自然融入家的第一处空间。',
    image: '/images/scene-entry-door.jpg',
    alt: '暖米白住宅玄关中的深胡桃木入户大门概念场景',
    conceptual: true,
  },
  {
    slug: 'thermal-break-window',
    title: '让采光进来，也把舒适留在室内',
    space: '客厅 / 窗边',
    decision: '结合窗洞比例、固定扇与开启扇关系，判断断桥结构、玻璃配置和五金开启方式。',
    summary: '在通透视野之外，也关注隔热、密封、通风与日常使用的平衡。',
    image: '/images/scene-thermal-break-window.jpg',
    alt: '暖色客厅中的深炭色断桥铝窗概念场景',
    conceptual: true,
  },
  {
    slug: 'glass-living-room',
    title: '客厅与阳台之间，保留光的流动',
    space: '客厅 / 阳台',
    decision: '窄边框玻璃移门让空间保持通透，同时明确开合与收口关系。',
    summary: '从视野、动线与日常清洁出发，判断玻璃分隔的比例和开启方式。',
    image: '/images/scene-glass-living.jpg',
    alt: '暖色客厅与阳台之间的黑色窄边框玻璃移门概念场景',
    conceptual: true,
  },
  {
    slug: 'oak-interior-door',
    title: '浅木门融入温暖克制的家',
    space: '过道 / 餐厅',
    decision: '让门扇、门套与木作保持一致的色温，五金作为克制的深色点。',
    summary: '关注门洞比例、开启方向与相邻柜体，让门在真实动线里自然使用。',
    image: '/images/scene-oak-door.jpg',
    alt: '浅木色室内门通向餐厅的温暖住宅概念场景',
    conceptual: true,
  },
];

export const journey = [
  ['01', '需求确认', '理解生活需求、风格偏好与使用场景'],
  ['02', '现场量尺', '记录洞口、墙体、完成面与开合条件'],
  ['03', '方案报价', '说明选择理由、配置差异与预算取舍'],
  ['04', '排期安装', '明确生产、进场与关键配合节点'],
  ['05', '验收售后', '完成验收记录并保留问题反馈入口'],
] as const;
