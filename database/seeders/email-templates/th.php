<?php

// Thai versions of the built-in email templates. Placeholders stay exactly as in English.
return [
    'client.welcome' => ['ยินดีต้อนรับสู่ {{ company.name }}', <<<'MD'
สวัสดี {{ client.first_name }}

ขอบคุณที่สร้างบัญชีกับ {{ company.name }}

เข้าสู่ระบบได้ทุกเมื่อเพื่อสั่งซื้อบริการ ชำระใบแจ้งหนี้ และติดต่อฝ่ายสนับสนุน:

[ไปที่บัญชีของคุณ]({{ client_area_url }})

หากคุณยังไม่ได้ตั้งรหัสผ่าน ให้ใช้ "ลืมรหัสผ่าน" ในหน้าเข้าสู่ระบบ

ขอบคุณ
{{ company.name }}
MD],
    'client.two_factor_code' => ['รหัสเข้าสู่ระบบ {{ company.name }} ของคุณ', <<<'MD'
สวัสดี {{ client.first_name }}

รหัสเข้าสู่ระบบของคุณคือ: **{{ code }}**

รหัสนี้ใช้ได้ 10 นาที ห้ามแจ้งรหัสนี้แก่ผู้อื่น: เจ้าหน้าที่ของเราจะไม่ขอรหัสนี้จากคุณ

หากคุณไม่ได้พยายามเข้าสู่ระบบ โปรดเปลี่ยนรหัสผ่านทันที

{{ company.name }}
MD],
    'order.confirmation' => ['ได้รับคำสั่งซื้อ #{{ order.number }} แล้ว', <<<'MD'
สวัสดี {{ client.first_name }}

ขอบคุณสำหรับคำสั่งซื้อ **#{{ order.number }}** ยอดรวมคือ **{{ order.total }}**

[ชำระใบแจ้งหนี้ {{ invoice.number }}]({{ invoice.url }})

เราจะตั้งค่าบริการของคุณทันทีที่ได้รับการชำระเงิน และส่งรายละเอียดให้ทางอีเมล

ขอบคุณ
{{ company.name }}
MD],
    'invoice.created' => ['ใบแจ้งหนี้ {{ invoice.number }} พร้อมแล้ว', <<<'MD'
สวัสดี {{ client.first_name }}

ใบแจ้งหนี้ **{{ invoice.number }}** ยอด **{{ invoice.total }}** พร้อมแล้ว ครบกำหนดชำระวันที่ **{{ invoice.due_date }}**

[ดูและชำระใบแจ้งหนี้]({{ invoice.url }})

ขอบคุณ
{{ company.name }}
MD],
    'invoice.credit_note' => ['ใบลดหนี้ {{ credit_note.number }} สำหรับใบแจ้งหนี้ {{ invoice.number }}', <<<'MD'
สวัสดี {{ client.first_name }}

เราได้ออกใบลดหนี้ **{{ credit_note.number }}** ยอด **{{ credit_note.total }}** สำหรับใบแจ้งหนี้ **{{ invoice.number }}**

{{ credit_note.note }}

[ดูใบแจ้งหนี้และใบลดหนี้]({{ invoice.url }})

{{ company.name }}
MD],
    'invoice.payment_received' => ['ได้รับการชำระเงินสำหรับใบแจ้งหนี้ {{ invoice.number }} แล้ว', <<<'MD'
สวัสดี {{ client.first_name }}

เราได้รับการชำระเงินสำหรับใบแจ้งหนี้ **{{ invoice.number }}** แล้ว ขอบคุณ!

[ดูใบแจ้งหนี้]({{ invoice.url }})

{{ company.name }}
MD],
    'invoice.autopay_upcoming' => ['ใบแจ้งหนี้ {{ invoice.number }} จะชำระอัตโนมัติในวันที่ {{ charge_date }}', <<<'MD'
สวัสดี {{ client.first_name }}

ในวันที่ **{{ charge_date }}** เราจะเรียกเก็บ **{{ invoice.balance }}** สำหรับใบแจ้งหนี้ **{{ invoice.number }}** จาก {{ payment_method.name }} ของคุณ คุณไม่ต้องดำเนินการใดๆ

[ดูใบแจ้งหนี้]({{ invoice.url }})

หากต้องการใช้บัตรอื่นหรือปิดการชำระเงินอัตโนมัติ ให้เปิด [วิธีการชำระเงิน]({{ payment_methods_url }})

{{ company.name }}
MD],
    'invoice.autopay_failed' => ['เราไม่สามารถเรียกเก็บเงินจาก {{ payment_method.name }} ของคุณสำหรับใบแจ้งหนี้ {{ invoice.number }}', <<<'MD'
สวัสดี {{ client.first_name }}

วันนี้เราพยายามเรียกเก็บ **{{ invoice.balance }}** สำหรับใบแจ้งหนี้ **{{ invoice.number }}** จาก {{ payment_method.name }} ของคุณ แต่ไม่สำเร็จ: {{ failure }} ไม่มีการหักเงินจากบัญชีของคุณ

{{ next_try }}

[ชำระใบแจ้งหนี้]({{ invoice.url }})

หากบัตรของคุณหมดอายุหรือมีหมายเลขใหม่ ให้[เพิ่มบัตรใหม่]({{ payment_methods_url }}) แล้วเราจะใช้บัตรนั้นในการลองครั้งถัดไป

{{ company.name }}
MD],
    'payment.method_expiring' => ['บัตรที่บันทึกไว้ของคุณใกล้หมดอายุ', <<<'MD'
สวัสดี {{ client.first_name }}

{{ payment_method.name }} ของคุณ ซึ่งใช้ชำระค่าต่ออายุโดยอัตโนมัติ จะหมดอายุเมื่อสิ้น {{ payment_method.expires }}

[เพิ่มบัตรใหม่ของคุณ]({{ payment_methods_url }}) เพื่อให้การต่ออายุยังคงชำระเงินได้โดยอัตโนมัติ

{{ company.name }}
MD],
    'invoice.reminder' => ['แจ้งเตือน: ใบแจ้งหนี้ {{ invoice.number }} เลยกำหนดชำระแล้ว', <<<'MD'
สวัสดี {{ client.first_name }}

ใบแจ้งหนี้ **{{ invoice.number }}** ยอด **{{ invoice.balance }}** ครบกำหนดชำระวันที่ {{ invoice.due_date }} และยังไม่ได้รับการชำระ

[ชำระเงินตอนนี้]({{ invoice.url }})

บริการที่มีใบแจ้งหนี้ค้างชำระจะถูกระงับหลังจากผ่านไปไม่กี่วัน หากคุณชำระเงินแล้ว โปรดไม่ต้องสนใจอีเมลนี้

{{ company.name }}
MD],
    'service.welcome' => ['{{ service.product }} ของคุณพร้อมใช้งานแล้ว', <<<'MD'
สวัสดี {{ client.first_name }}

**{{ service.product }}** ของคุณสำหรับ **{{ service.domain }}** ได้รับการตั้งค่าและพร้อมใช้งานแล้ว

- ชื่อผู้ใช้: {{ service.username }}
- เซิร์ฟเวอร์: {{ service.server }}

รหัสผ่านและการเข้าสู่แผงควบคุมในคลิกเดียวอยู่ในพื้นที่ลูกค้าของคุณ:

[เปิดรายละเอียดบริการ]({{ service.url }})

{{ company.name }}
MD],
    'service.suspended' => ['บริการถูกระงับ: {{ service.domain }}', <<<'MD'
สวัสดี {{ client.first_name }}

**{{ service.product }}** ของคุณสำหรับ **{{ service.domain }}** ถูกระงับแล้ว

เหตุผล: {{ reason }}

ชำระใบแจ้งหนี้ที่ค้างอยู่เพื่อเปิดใช้งานอีกครั้งโดยอัตโนมัติ หรือตอบกลับอีเมลนี้หากต้องการความช่วยเหลือ

[ไปที่บัญชีของคุณ]({{ client_area_url }})

{{ company.name }}
MD],
    'service.plan_changed' => ['แพ็กเกจของคุณเปลี่ยนเป็น {{ plan.new }} แล้ว', <<<'MD'
สวัสดี {{ client.first_name }}

บริการ **{{ service.domain }}** ของคุณเปลี่ยนจาก **{{ plan.old }}** เป็น **{{ plan.new }}** แล้ว

ตั้งแต่การต่ออายุครั้งถัดไป คุณจะชำระ {{ plan.amount }} ({{ plan.cycle }}) {{ plan.note }}

[ดูบริการของคุณ]({{ service.url }})

{{ company.name }}
MD],
    'service.unsuspended' => ['บริการกลับมาใช้งานได้แล้ว: {{ service.domain }}', <<<'MD'
สวัสดี {{ client.first_name }}

ข่าวดี: **{{ service.product }}** ของคุณสำหรับ **{{ service.domain }}** กลับมาใช้งานได้แล้ว

{{ company.name }}
MD],
    'service.resources_changed' => ['ทรัพยากรเซิร์ฟเวอร์ของคุณมีการเปลี่ยนแปลง', <<<'MD'
สวัสดี {{ client.first_name }}

ทรัพยากรของ **{{ service.domain }}** มีการเปลี่ยนแปลง:

{{ resources.list }}

ตั้งแต่การต่ออายุครั้งถัดไป คุณจะชำระ {{ resources.amount }} ({{ resources.cycle }}) สำหรับส่วนเพิ่มเติมเหล่านี้ {{ resources.note }}

[ดูบริการของคุณ]({{ service.url }})

{{ company.name }}
MD],
    'service.server_change' => ['มีการเปลี่ยนแปลงบนเซิร์ฟเวอร์ {{ service.domain }} ของคุณ', <<<'MD'
สวัสดี {{ client.first_name }}

{{ change.text }}

- เวลา: {{ change.time }}
- จากที่อยู่ IP: {{ change.ip }}

หากคุณไม่ได้ดำเนินการนี้ โปรดเปลี่ยนรหัสผ่านพื้นที่ลูกค้าทันทีและติดต่อเรา

[ดูบริการของคุณ]({{ service.url }})

{{ company.name }}
MD],
    'service.dedicated_ready' => ['เซิร์ฟเวอร์ {{ service.product }} ของคุณพร้อมแล้ว', <<<'MD'
สวัสดี {{ client.first_name }}

เซิร์ฟเวอร์เฉพาะ **{{ service.product }}** ของคุณพร้อมแล้ว

- ที่อยู่ IP: {{ machine.ip }}
- ที่อยู่ IP ทั้งหมด: {{ machine.ips }}
- ชื่อเข้าสู่ระบบ: {{ machine.username }}
- ชื่อโฮสต์: {{ machine.hostname }}
- ตำแหน่งที่ตั้ง: {{ machine.location }}

รหัสผ่านของคุณอยู่ในพื้นที่ลูกค้า โปรดเปลี่ยนรหัสผ่านหลังจากเข้าสู่ระบบครั้งแรก

[เปิดเซิร์ฟเวอร์ของคุณ]({{ service.url }})

{{ company.name }}
MD],
    'domain.registered' => ['โดเมน {{ domain.name }} ของคุณจดทะเบียนแล้ว', <<<'MD'
สวัสดี {{ client.first_name }}

ข่าวดี: **{{ domain.name }}** ได้จดทะเบียนในชื่อของคุณแล้วจนถึง **{{ domain.expires_at }}**

เนมเซิร์ฟเวอร์: {{ domain.nameservers }}

อาจใช้เวลาสองสามชั่วโมงกว่าโดเมนจะใช้งานได้ทุกที่บนอินเทอร์เน็ต

[จัดการโดเมนของคุณ]({{ domain.url }})

{{ company.name }}
MD],
    'domain.transfer_started' => ['เริ่มการโอนย้าย {{ domain.name }} แล้ว', <<<'MD'
สวัสดี {{ client.first_name }}

เราได้เริ่มโอนย้าย **{{ domain.name }}** มายังเราแล้ว โดยปกติการโอนย้ายใช้เวลา 5 ถึง 7 วัน

ผู้รับจดทะเบียนปัจจุบันของคุณอาจส่งอีเมลให้คุณอนุมัติการโอนย้าย การอนุมัติจะทำให้การโอนย้ายเร็วขึ้น

[ติดตามการโอนย้าย]({{ domain.url }})

{{ company.name }}
MD],
    'domain.renewed' => ['ต่ออายุโดเมน {{ domain.name }} ของคุณแล้ว', <<<'MD'
สวัสดี {{ client.first_name }}

ขอบคุณ! **{{ domain.name }}** ได้รับการต่ออายุแล้ว และจะหมดอายุในวันที่ **{{ domain.expires_at }}**

[จัดการโดเมนของคุณ]({{ domain.url }})

{{ company.name }}
MD],
    'domain.expiring' => ['{{ domain.name }} จะหมดอายุในอีก {{ days_left }} วัน', <<<'MD'
สวัสดี {{ client.first_name }}

โดเมน **{{ domain.name }}** ของคุณจะหมดอายุในวันที่ **{{ domain.expires_at }}** และจะไม่ต่ออายุเอง

หากต้องการเก็บโดเมนไว้ โปรดต่ออายุตอนนี้ โดเมนที่หมดอายุจะหยุดทำงาน และผู้อื่นอาจจดทะเบียนไป

[ต่ออายุ {{ domain.name }}]({{ domain.url }})

{{ company.name }}
MD],
    'ticket.opened' => ['[ทิกเก็ต #{{ ticket.number }}] {{ ticket.subject }}', <<<'MD'
สวัสดี {{ client.first_name }}

เราได้รับทิกเก็ตของคุณแล้ว และจะตอบกลับโดยเร็วที่สุด

**{{ ticket.subject }}** · {{ ticket.department }}

[ดูทิกเก็ตของคุณ]({{ ticket.url }})

{{ company.name }}
MD],
    'ticket.reply' => ['[ทิกเก็ต #{{ ticket.number }}] การตอบกลับใหม่: {{ ticket.subject }}', <<<'MD'
สวัสดี {{ client.first_name }}

{{ reply.author }} ได้ตอบกลับทิกเก็ตของคุณ:

{{ reply.message }}

[ดูและตอบกลับทิกเก็ต]({{ ticket.url }})

{{ company.name }}
MD],
    'admin.security_alert' => ['พบปัญหาความปลอดภัยใหม่ใน {{ company.name }}', <<<'MD'
สวัสดี {{ staff.name }}

การตรวจสอบสถานะระบบเมื่อคืนนี้พบสิ่งใหม่ที่ต้องให้คุณดำเนินการ:

{{ issues }}

คะแนนความปลอดภัยของคุณคือ **{{ score }} จาก 100**

[เปิดสถานะระบบ]({{ admin_url }})

คุณได้รับอีเมลนี้เนื่องจากคุณมีสิทธิ์ดูและแก้ไขปัญหาความปลอดภัย รหัสผ่านและเนื้อหาไฟล์จะไม่ถูกใส่ไว้ในอีเมลเหล่านี้
MD],
    'admin.new_order' => ['คำสั่งซื้อใหม่ #{{ order.number }} จาก {{ client.name }}', <<<'MD'
{{ client.name }} ({{ client.email }}) ได้สั่งซื้อ **#{{ order.number }}** ยอด **{{ order.total }}**

[เปิดคำสั่งซื้อ]({{ admin_url }})
MD],
    'admin.ticket_opened' => ['ทิกเก็ตใหม่ #{{ ticket.number }}: {{ ticket.subject }}', <<<'MD'
{{ client.name }} ได้เปิดทิกเก็ตในแผนก {{ ticket.department }}:

**{{ ticket.subject }}**

{{ reply.message }}

[ตอบกลับตอนนี้]({{ admin_url }})
MD],
    'admin.ticket_reply' => ['ลูกค้าตอบกลับทิกเก็ต #{{ ticket.number }}', <<<'MD'
{{ client.name }} ได้ตอบกลับ **{{ ticket.subject }}**:

{{ reply.message }}

[ตอบกลับตอนนี้]({{ admin_url }})
MD],
    'quote.sent' => ['ใบเสนอราคา {{ quote.number }} ของคุณจาก {{ company.name }}', <<<'MD'
สวัสดี {{ client.first_name }}

นี่คือใบเสนอราคา **{{ quote.number }}** ของคุณ: {{ quote.subject }}

ยอดรวม: **{{ quote.total }}** ใช้ได้ถึง {{ quote.valid_until }}

[ดูและยอมรับใบเสนอราคา]({{ quote.url }})

มีคำถาม? ตอบกลับอีเมลนี้ได้เลย

{{ company.name }}
MD],
    'admin.quote_accepted' => ['{{ client.name }} ยอมรับใบเสนอราคา {{ quote.number }} แล้ว', <<<'MD'
{{ client.name }} ได้ยอมรับใบเสนอราคา **{{ quote.number }}** ({{ quote.subject }}) ยอด **{{ quote.total }}**

ได้สร้างใบแจ้งหนี้ {{ invoice.number }} สำหรับใบเสนอราคานี้แล้ว

[เปิดใบเสนอราคา]({{ admin_url }})
MD],
    'admin.resources_failed' => ['ไม่ได้ตั้งค่าทรัพยากรของบริการ #{{ service.id }}', <<<'MD'
เซิร์ฟเวอร์ไม่ได้รับทรัพยากรใหม่ของ **{{ service.product }}** (บริการ #{{ service.id }}) สำหรับ {{ client.name }} ลูกค้าได้ชำระเงินสำหรับทรัพยากรเหล่านี้แล้ว

สิ่งที่ล้มเหลว: {{ error }}

เปิดบริการแล้วกด **ลองอีกครั้ง** หรือตั้งค่าทรัพยากรบนเซิร์ฟเวอร์ด้วยตนเอง แล้วกด **ทำเครื่องหมายว่าเสร็จแล้ว**

[เปิดบริการ]({{ admin_url }})
MD],
    'admin.resources_by_hand' => ['ตั้งค่าทรัพยากรของบริการ #{{ service.id }} ด้วยตนเอง', <<<'MD'
{{ client.name }} ได้เปลี่ยนทรัพยากรของ **{{ service.product }}** (บริการ #{{ service.id }}) ไม่มีโมดูลเซิร์ฟเวอร์ใดตั้งค่าทรัพยากรให้บริการนี้ ดังนั้นโปรดตั้งค่าบนเซิร์ฟเวอร์ด้วยตนเอง:

{{ resources.list }}

จากนั้นกด **ทำเครื่องหมายว่าเสร็จแล้ว** ในหน้าบริการ

[เปิดบริการ]({{ admin_url }})
MD],
    'admin.machine_install' => ['ติดตั้งระบบปฏิบัติการบน {{ machine.name }} สำหรับ {{ client.name }}', <<<'MD'
{{ client.name }} ได้ชำระเงินสำหรับ **{{ service.product }}** (บริการ #{{ service.id }}) แล้ว

- เครื่อง: {{ machine.name }}
- ตำแหน่งที่ตั้ง: {{ machine.location }} แร็ค {{ machine.rack }} {{ machine.rack_unit }}
- ระบบ: {{ machine.default_os }}

ติดตั้งระบบ แล้วกด **เซิร์ฟเวอร์พร้อมแล้ว** ในหน้าบริการ จากนั้นลูกค้าจะได้รับอีเมล

[เปิดบริการ]({{ admin_url }})
MD],
    'admin.machine_wipe' => ['ล้างข้อมูล {{ machine.name }} ก่อนนำไปขายอีกครั้ง', <<<'MD'
บริการ #{{ service.id }} สิ้นสุดแล้ว ดังนั้น **{{ machine.name }}** ({{ machine.location }}) จึงถูกปิดเครื่องและรอการล้างข้อมูล

ลบข้อมูลในดิสก์ทั้งหมด แล้วกด **ยืนยันการล้างข้อมูล** เครื่องนี้จะไม่ถูกนำไปขายอีกจนกว่าจะดำเนินการเสร็จ

[เปิดเครื่อง]({{ admin_url }})
MD],
    'admin.machine_alert' => ['{{ machine.name }}: {{ alert.title }}', <<<'MD'
{{ alert.message }}

[เปิดเครื่อง]({{ admin_url }})
MD],
    'affiliate.commission' => ['คุณได้รับ {{ commission.amount }} จากการแนะนำ', <<<'MD'
สวัสดี {{ client.first_name }}

ผู้ที่คุณแนะนำให้มาใช้ {{ company.name }} ได้ชำระใบแจ้งหนี้แล้ว และคุณได้รับ **{{ commission.amount }}**

ยอดนี้จะพร้อมใช้งานในวันที่ {{ commission.available_on }} จากนั้นคุณสามารถย้ายไปยังกระเป๋าเงินของคุณได้

[ดูบัญชีพันธมิตรของคุณ]({{ affiliate_url }})

{{ company.name }}
MD],
    'marketplace.license' => ['คีย์ลิขสิทธิ์ {{ item.name }} ของคุณ', <<<'MD'
สวัสดี {{ client.first_name }}

ขอบคุณที่ซื้อ **{{ item.name }}** นี่คือคีย์ลิขสิทธิ์ของคุณ:

**{{ license.key }}**

หากต้องการติดตั้ง ให้เปิดพื้นที่ผู้ดูแลระบบ Nuvabill ของคุณ ไปที่ **มาร์เก็ตเพลส** ค้นหา {{ item.name }} วางคีย์ แล้วคลิก **ติดตั้ง**

คีย์จะผูกกับเว็บไซต์แรกที่คุณติดตั้ง คุณสามารถย้ายไปยังเว็บไซต์อื่นได้จากบัญชีของคุณ

[เปิดบัญชีของคุณ]({{ service.url }})

{{ company.name }}
MD],
    'marketplace.review' => ['{{ item.name }} {{ item.version }}: {{ review.outcome }}', <<<'MD'
สวัสดี {{ client.first_name }}

เราได้ตรวจสอบ **{{ item.name }} {{ item.version }}** แล้ว ผลคือ: {{ review.outcome }}

{{ review.message }}

[เปิดบัญชีนักพัฒนาของคุณ]({{ developer_url }})

{{ company.name }}
MD],
];
