import { useState, type SyntheticEvent } from 'react';
import { ArrowRightIcon, CheckCircleIcon, WarningCircleIcon } from '@phosphor-icons/react';

interface Props {
  endpoint?: string;
  apiKey?: string;
  subject?: string;
}

type State = 'idle' | 'submitting' | 'success' | 'error';

export default function ContactForm({ endpoint, apiKey, subject }: Props) {
  const formApiKey = apiKey?.replace(/^\uFEFF/, '').trim();
  const isConfigured = Boolean(endpoint && formApiKey);
  const [state, setState] = useState<State>('idle');
  const [message, setMessage] = useState(
    isConfigured
      ? '填写后，我们会根据需求与现场条件确认下一步。'
      : '表单接收服务尚未配置，请在正式上线前补充 StaticForms 配置。',
  );

  async function submit(event: SyntheticEvent<HTMLFormElement, SubmitEvent>) {
    event.preventDefault();
    if (!isConfigured || state === 'submitting' || state === 'success') return;

    const form = event.currentTarget;
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    setState('submitting');
    setMessage('正在提交预约需求…');

    try {
      const response = await fetch(endpoint!, {
        method: 'POST',
        body: new FormData(form),
        headers: { Accept: 'application/json' },
      });

      if (!response.ok) throw new Error('submit failed');

      form.reset();
      setState('success');
      setMessage('预约需求已提交，我们会通过你填写的联系方式确认下一步。');
    } catch {
      setState('error');
      setMessage('暂时未能提交，请稍后重试。你填写的内容仍保留在页面中。');
    }
  }

  return <form className="contact-form" action={endpoint} method="POST" onSubmit={submit}>
    <input type="hidden" name="apiKey" value={formApiKey ?? ''} />
    <input type="hidden" name="subject" value={subject ?? '乐宅.Life 预约量尺申请'} />

    <div className="field"><label htmlFor="name">称呼</label><input id="name" name="name" autoComplete="name" required maxLength={40} /></div>
    <div className="field"><label htmlFor="phone">手机号码</label><input id="phone" name="phone" autoComplete="tel" inputMode="tel" pattern="[0-9+\-\s]{7,20}" required /></div>
    <div className="field"><label htmlFor="email">电子邮箱</label><input id="email" name="email" type="email" autoComplete="email" /></div>
    <div className="field"><label htmlFor="area">所在区域</label><input id="area" name="area" placeholder="例如：惠城区" maxLength={60} /></div>
    <div className="field"><label htmlFor="stage">装修阶段</label><select id="stage" name="stage" defaultValue=""><option value="" disabled>请选择</option><option>正在规划</option><option>设计方案中</option><option>准备施工</option><option>现场施工中</option></select></div>
    <div className="field field--full"><label htmlFor="message">门窗需求</label><textarea id="message" name="message" placeholder="可以描述空间、喜欢的效果、现场情况或希望解决的问题" required maxLength={1000} /></div>
    <label className="checkbox field--full"><input type="checkbox" name="privacy" value="agreed" required />我同意将以上信息用于本次预约沟通，不用于其他用途。</label>
    <button className="button field--full" type="submit" disabled={!isConfigured || state === 'submitting' || state === 'success'}>{state === 'submitting' ? '提交中…' : state === 'success' ? '已提交' : '提交预约需求'}<ArrowRightIcon size={18} weight="bold" /></button>
    <p className="form-status" data-state={state} aria-live="polite">{state === 'success' ? <CheckCircleIcon size={18} weight="fill" /> : state === 'error' ? <WarningCircleIcon size={18} weight="fill" /> : null}{message}</p>
  </form>;
}
